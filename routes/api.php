<?php

use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\AnalyticsController;
use App\Http\Controllers\Api\AppConfigController;
use App\Http\Controllers\Api\AppController;
use App\Http\Controllers\Api\AppVersionController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BusinessProfileController;
use App\Http\Controllers\Api\CredentialController;
use App\Http\Controllers\Api\DeviceController;
use App\Http\Controllers\Api\EventController;
use App\Http\Controllers\Api\OrganizationController;
use App\Http\Controllers\Api\PublicConfigController;
use App\Http\Controllers\Api\PushCampaignController;
use App\Http\Controllers\Api\TemplateController;
use App\Http\Controllers\Api\UploadController;
use Illuminate\Support\Facades\Route;

/**
 * The panel's contract, endpoint for endpoint.
 *
 * `appforge-web/src/lib/api/client.ts` is the spec: paths, methods and payload
 * shapes are validated there with zod, so a mismatch shows up as a loud parse
 * error rather than an undefined three components deep. If you change anything
 * here, change the zod schema in the same commit.
 */
Route::prefix('auth')->group(function () {
    // Unauthenticated and therefore the whole attack surface. The blanket
    // limit is per IP; AuthController adds a per-account one on top.
    Route::middleware('throttle:20,1')->group(function () {
        Route::post('register', [AuthController::class, 'register']);
        Route::post('login', [AuthController::class, 'login']);
    });

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
    });
});

Route::get('templates', [TemplateController::class, 'index']);

/**
 * What a customer's phone downloads at launch. Unauthenticated by design — a
 * config is served into a binary anyone can unpack, so it is public, which is
 * precisely why ConfigValidator refuses any key not on the allowlist.
 *
 * Throttled generously: every cold start of every app hits this. In production
 * it is served from a CDN and never reaches PHP.
 */
Route::middleware('throttle:120,1')
    ->get('public/configs/{app}', [PublicConfigController::class, 'show']);

/**
 * A phone registering for notifications. Unauthenticated for the same reason
 * the config endpoint is — the shell has no user — and write-only, returning
 * 204 whether or not the app exists so it cannot be used to enumerate ids.
 */
Route::middleware('throttle:30,1')
    ->post('public/apps/{app}/devices', [DeviceController::class, 'store']);

/**
 * A phone reporting what happened inside it, batched. Unauthenticated and
 * write-only like the device endpoint, and 204 for the same reason.
 *
 * A more generous limit than devices because the shell posts on launch and on
 * background rather than once per install — but still per IP, so a whole café
 * on one wifi shares it. Losing a batch costs a few counters and nothing else,
 * which is the right thing to be cheap about.
 */
Route::middleware('throttle:60,1')
    ->post('public/apps/{app}/events', [EventController::class, 'store']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('apps', [AppController::class, 'index']);
    Route::post('apps', [AppController::class, 'store']);
    Route::get('apps/{app}', [AppController::class, 'show']);
    Route::patch('apps/{app}', [AppController::class, 'update']);
    Route::delete('apps/{app}', [AppController::class, 'destroy']);

    // The editor draft: one per app, overwritten on save.
    Route::get('apps/{app}/config', [AppConfigController::class, 'show']);
    Route::put('apps/{app}/config', [AppConfigController::class, 'update']);

    // Immutable published snapshots. There is no update or delete route, and
    // that is the whole point — see App\Models\AppVersion.
    Route::get('apps/{app}/versions', [AppVersionController::class, 'index']);
    Route::post('apps/{app}/versions', [AppVersionController::class, 'store']);

    // The credential vault. Note there is no route that reads a secret back —
    // see CredentialController. Set and replace only.
    Route::get('apps/{app}/credentials', [CredentialController::class, 'index']);
    Route::put('apps/{app}/credentials/{type}', [CredentialController::class, 'store']);
    Route::delete('apps/{app}/credentials/{type}', [CredentialController::class, 'destroy']);

    // Engagement: composing, scheduling and sending push notifications.
    Route::get('apps/{app}/push', [PushCampaignController::class, 'index']);
    Route::post('apps/{app}/push', [PushCampaignController::class, 'store']);
    Route::get('apps/{app}/push/audience', [PushCampaignController::class, 'audience']);
    Route::post('apps/{app}/push/{campaign}/send', [PushCampaignController::class, 'send']);
    Route::delete('apps/{app}/push/{campaign}', [PushCampaignController::class, 'destroy']);

    // Read-only. Counters are written by phones, never by the panel.
    Route::get('apps/{app}/analytics', [AnalyticsController::class, 'show']);

    Route::middleware('throttle:60,1')
        ->post('uploads', [UploadController::class, 'store']);

    Route::get('profiles', [BusinessProfileController::class, 'index']);
    Route::get('profiles/{category}', [BusinessProfileController::class, 'show']);
    Route::put('profiles/{category}', [BusinessProfileController::class, 'update']);
    Route::delete('profiles/{category}', [BusinessProfileController::class, 'destroy']);

    Route::patch('account', [AccountController::class, 'update']);
    Route::patch('organization', [OrganizationController::class, 'update']);
});
