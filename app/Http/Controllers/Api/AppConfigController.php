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

        return $request->user()->organization->apps()->findOrFail($id);
    }
}
