<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AppVersionResource;
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
            // Without the config blob: a version list is a list, and shipping
            // twenty configs to render twenty dates is wasteful.
            $model->versions()->orderByDesc('version')->get(['id', 'app_id', 'version', 'schema_version', 'notes', 'created_at'])
        );
    }

    /**
     * Freeze the current draft as a new immutable version.
     *
     * The draft is re-validated here even though it was validated on save. The
     * two are not the same check in practice: rules tighten over time, and a
     * draft written months ago against looser rules must not reach a phone
     * just because it was legal when it was typed.
     */
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

        // `ready` means "there is something publishable"; it does not mean the
        // app is in a store. Only a build that a human has shepherded through
        // review sets `live` and `published_version`.
        if ($model->status === 'draft' || $model->status === 'rejected') {
            $model->update(['status' => 'ready']);
        }

        return (new AppVersionResource($version))->response()->setStatusCode(201);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function createNextVersion(ClientApp $app, array $config, ?string $notes, string $userId): AppVersion
    {
        // Two publishes landing together must not both become version 4. The
        // unique index on (app_id, version) is the authority; this retries
        // rather than trusting a read-then-write to have been atomic.
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
