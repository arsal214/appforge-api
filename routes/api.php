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

Route::prefix('auth')->group(function () {

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

Route::middleware('throttle:120,1')
    ->get('public/configs/{app}', [PublicConfigController::class, 'show']);

Route::middleware('throttle:30,1')
    ->post('public/apps/{app}/devices', [DeviceController::class, 'store']);

Route::middleware('throttle:60,1')
    ->post('public/apps/{app}/events', [EventController::class, 'store']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('apps', [AppController::class, 'index']);
    Route::post('apps', [AppController::class, 'store']);
    Route::get('apps/{app}', [AppController::class, 'show']);
    Route::patch('apps/{app}', [AppController::class, 'update']);
    Route::delete('apps/{app}', [AppController::class, 'destroy']);

    Route::get('apps/{app}/config', [AppConfigController::class, 'show']);
    Route::put('apps/{app}/config', [AppConfigController::class, 'update']);

    Route::get('apps/{app}/versions', [AppVersionController::class, 'index']);
    Route::post('apps/{app}/versions', [AppVersionController::class, 'store']);

    Route::get('apps/{app}/credentials', [CredentialController::class, 'index']);
    Route::put('apps/{app}/credentials/{type}', [CredentialController::class, 'store']);
    Route::delete('apps/{app}/credentials/{type}', [CredentialController::class, 'destroy']);

    Route::get('apps/{app}/push', [PushCampaignController::class, 'index']);
    Route::post('apps/{app}/push', [PushCampaignController::class, 'store']);
    Route::get('apps/{app}/push/audience', [PushCampaignController::class, 'audience']);
    Route::post('apps/{app}/push/{campaign}/send', [PushCampaignController::class, 'send']);
    Route::delete('apps/{app}/push/{campaign}', [PushCampaignController::class, 'destroy']);

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
