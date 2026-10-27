<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AppVersionResource;
use App\Http\Resources\Iso8601;
use App\Models\App as ClientApp;
use App\Models\AppVersion;
use App\Support\Config\ConfigValidator;
use AppForge\Schema\Contract;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AppVersionController extends Controller
{
    public function index(Request $request, string $app): AnonymousResourceCollection
    {
        $model = $this->findOwned($request, $app);

        return AppVersionResource::collection(

            $model->versions()->orderByDesc('version')->get(['id', 'app_id', 'version', 'schema_version', 'notes', 'created_at'])
        );
    }

    public function store(Request $request, string $app): JsonResponse
    {
        $model = $this->findOwned($request, $app);
        $draft = $model->config;

        if ($draft === null) {
            abort(422, 'There is nothing to publish yet. Save your changes first.');
        }

        $errors = (new ConfigValidator)->validate($draft->config);
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $notes = $request->input('notes');
        if ($notes !== null && (! is_string($notes) || mb_strlen($notes) > 500)) {
            throw ValidationException::withMessages([
                'notes' => ['Keep the note under 500 characters.'],
            ]);
        }

        $version = $this->createNextVersion($model, $draft->config, $notes, $request->user()->getKey());

        if ($model->status === 'draft' || $model->status === 'rejected') {
            $model->update(['status' => 'ready']);
        }

        return (new AppVersionResource($version))->response()->setStatusCode(201);
    }

    /**
     * Puts a published version back into the draft.
     *
     * The config is re-validated on the way in even though it passed on the way
     * out: a version can predate a contract change, and restoring one that the
     * current shell can no longer render would be a broken app the customer
     * cannot see until it ships. Refusing it names the field instead.
     */
    public function restore(Request $request, string $app, string $version): JsonResponse
    {
        $model = $this->findOwned($request, $app);

        $snapshot = $model->versions()->where('version', (int) $version)->firstOrFail();

        $errors = (new ConfigValidator)->validate($snapshot->config);
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $record = $model->config()->updateOrCreate(
            ['app_id' => $model->id],
            [
                'schema_version' => Contract::SCHEMA_VERSION,
                'config' => $snapshot->config,
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

    private function createNextVersion(ClientApp $app, array $config, ?string $notes, string $userId): AppVersion
    {

        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                return DB::transaction(function () use ($app, $config, $notes, $userId) {
                    $next = (int) $app->versions()->lockForUpdate()->max('version') + 1;

                    return $app->versions()->create([
                        'version' => $next,
                        'schema_version' => Contract::SCHEMA_VERSION,
                        'config' => $config,
                        'notes' => $notes,
                        'created_by' => $userId,
                    ]);
                });
            } catch (QueryException $exception) {
                if (! $this->isDuplicateKey($exception) || $attempt === 2) {
                    throw $exception;
                }
            }
        }

        throw new \RuntimeException('Unreachable.');
    }

    private function isDuplicateKey(QueryException $exception): bool
    {
        return ($exception->errorInfo[1] ?? null) === 1062;
    }

    private function findOwned(Request $request, string $id): ClientApp
    {
        return $request->user()->organization->apps()->findOrFail($id);
    }
}
