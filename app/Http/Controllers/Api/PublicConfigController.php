<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * What a customer's phone downloads at launch.
 *
 * The one unauthenticated endpoint that serves customer data, and that is
 * correct rather than an oversight: a config is baked into the fetch path of an
 * app binary anyone can unpack, so it is public by construction. This is exactly
 * why `ConfigValidator` refuses unknown keys — the allowlist is what guarantees
 * nothing private ever reaches this response.
 *
 * Two things it deliberately does not do:
 *
 *  - **No organisation, app name, status or timestamps.** Only the config. An
 *    app id is guessable in principle, and a response that also revealed which
 *    business owns it, or that they are a customer of ours, would be a leak. The
 *    config already contains what the app displays and nothing more.
 *  - **No draft.** Only published versions. A customer half-way through editing
 *    must not be broadcasting work in progress to every phone.
 *
 * In production this is served from a CDN or object store, not from PHP — every
 * cold start of every app on every device hits it. Laravel serving it is the
 * development and small-scale path; the shape of the response is chosen so a
 * static file can replace it with no shell change.
 */
class PublicConfigController extends Controller
{
    public function show(Request $request, string $app): JsonResponse
    {
        /**
         * The query builder, not Eloquent, and that is the whole point.
         *
         * `App` carries `OrganizationScope`. There is no authenticated user on
         * this route, so that scope correctly fails closed — it applies
         * `1 = 0` — and every lookup through the model returns nothing. The
         * first version of this controller used `App::query()` and 404'd for
         * every app in existence. It shipped past a green test suite because
         * the tests published through `actingAs` first, and Laravel's
         * RequestGuard kept that user resolved into the later request, so the
         * scope quietly matched the right organisation.
         *
         * `withoutGlobalScope()` would "fix" it and would be the wrong fix:
         * unscoping a tenant model is banned in this layer precisely so nobody
         * can do it by reflex. This read has no tenant — the audience is an end
         * consumer's phone, not an AppForge customer — so it does not use a
         * tenant-scoped model at all. Being raw is the signal.
         *
         * One query, and it honours soft deletes: a customer who deletes their
         * app must stop serving it to the phones that already have it.
         */
        $row = DB::table('app_versions')
            ->join('apps', 'apps.id', '=', 'app_versions.app_id')
            ->where('app_versions.app_id', $app)
            ->whereNull('apps.deleted_at')
            // The highest-numbered version: "the latest config the customer
            // published". Note this is **not** `apps.published_version`, which
            // records what the store *binary* was cut against. The two diverge
            // on purpose — the entire proposition of server-driven UI is that a
            // customer changes their app without a store release.
            ->orderByDesc('app_versions.version')
            ->first(['app_versions.id', 'app_versions.config']);

        if (! $row) {
            // No such app, deleted, or created but never published.
            return $this->notFound();
        }

        $config = json_decode((string) $row->config, true);

        if (! is_array($config)) {
            // A row that cannot be decoded is a bug in our pipeline, not
            // something a phone can act on. Serving a broken body would make the
            // shell refuse it and fall back to its cache anyway; a 404 gets it
            // there without pretending we returned a config.
            return $this->notFound();
        }

        return response()
            ->json(['data' => $config])
            ->withHeaders([
                // Short and revalidatable. Long enough that a launch spike does
                // not land on the origin, short enough that a customer who
                // publishes a fix sees it in a minute rather than an hour.
                'Cache-Control' => 'public, max-age=60, stale-while-revalidate=600',
                // A version is immutable, so its id is a perfect validator.
                'ETag' => '"'.$row->id.'"',
            ]);
    }

    private function notFound(): JsonResponse
    {
        // The same response for "no such app", "deleted app" and "never
        // published". Distinguishing them would let someone probe which app ids
        // exist, and no client can act on the difference anyway.
        return response()->json(['message' => 'Not found.'], 404);
    }
}
