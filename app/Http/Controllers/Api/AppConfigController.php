<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Iso8601;
use App\Models\App as ClientApp;
use App\Support\Config\ConfigValidator;
use App\Support\MediaUrl;
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
                'config' => $config
                    ? MediaUrl::forDelivery($config->config)
                    : null,
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

        $request->validate(['expectedUpdatedAt' => ['nullable', 'date']]);

        $current = $model->config;
        $expected = $request->input('expectedUpdatedAt');

        if ($current !== null && $expected !== null && ! $current->updated_at->equalTo($expected)) {
            return response()->json([
                'message' => 'Someone else changed this app while you had it open, so your changes were not saved.',
            ], 409);
        }

        $config = $request->input('config');
        $errors = (new ConfigValidator)->validate($config);

        if ($errors !== []) {

            throw ValidationException::withMessages($errors);
        }

        $record = $model->config()->updateOrCreate(
            ['app_id' => $model->id],
            [
                'schema_version' => Contract::SCHEMA_VERSION,
                'config' => MediaUrl::forStorage($config),
                'updated_by' => $request->user()->getKey(),
            ],
        );

        if ($model->status === 'rejected') {
            $model->update(['status' => 'draft']);
        }

        return response()->json([
            'data' => [
                'config' => MediaUrl::forDelivery($record->config),
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
