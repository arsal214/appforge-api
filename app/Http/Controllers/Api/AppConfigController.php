<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Iso8601;
use App\Models\App as ClientApp;
use App\Support\Config\ConfigValidator;
use AppForge\Schema\Contract;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The editor draft.
 *
 * Until now the panel kept the draft in `localStorage`, which means a customer
 * who edits on a laptop and opens their phone finds their work gone. This moves
 * the source of truth to the server; the store keeps its local copy as a cache
 * and an offline buffer, not as the record.
 */
class AppConfigController extends Controller
{
    public function show(Request $request, string $app): JsonResponse
    {
        $model = $this->findOwned($request, $app);
        $config = $model->config;

        return response()->json([
            'data' => [
                'config' => $config?->config,
                'schemaVersion' => $config?->schema_version ?? Contract::SCHEMA_VERSION,
                'updatedAt' => $config
                    ? Iso8601::from($config->updated_at)
                    : null,
            ],
        ]);
    }

    public function update(Request $request, string $app): JsonResponse
    {
        $model = $this->findOwned($request, $app);

        $config = $request->input('config');
        $errors = (new ConfigValidator)->validate($config);

        if ($errors !== []) {
            // Keyed by dotted path (`config.screens.0.sections.2.props.title`)
            // so the panel can point at the control that is wrong rather than
            // saying "invalid" and leaving the customer to hunt.
            throw ValidationException::withMessages($errors);
        }

        $record = $model->config()->updateOrCreate(
            ['app_id' => $model->id],
            [
                'schema_version' => Contract::SCHEMA_VERSION,
                'config' => $config,
                'updated_by' => $request->user()->getKey(),
            ],
        );

        // Editing the draft of an app that was rejected puts it back in the
        // customer's court. A live app keeps its status: the live version is
        // whatever was last published, not whatever is in the editor.
        if ($model->status === 'rejected') {
            $model->update(['status' => 'draft']);
        }

        return response()->json([
            'data' => [
                'config' => $record->config,
                'schemaVersion' => $record->schema_version,
                'updatedAt' => Iso8601::from($record->updated_at),
            ],
        ]);
    }

    private function findOwned(Request $request, string $id): ClientApp
    {
        // Same rule as everywhere else: another organisation's app is 404.
        return $request->user()->organization->apps()->findOrFail($id);
    }
}
