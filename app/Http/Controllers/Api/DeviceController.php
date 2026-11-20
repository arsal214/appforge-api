<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppDevice;
use App\Support\PublicApp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeviceController extends Controller
{
    public function store(Request $request, string $app): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'min:16', 'max:512'],
            'platform' => ['required', 'in:ios,android'],
            'locale' => ['nullable', 'string', 'max:16'],
            'shellVersion' => ['nullable', 'string', 'max:32'],
        ]);

        $exists = PublicApp::exists($app);

        if ($exists) {
            AppDevice::updateOrCreate(
                [
                    'app_id' => $app,
                    'token_hash' => AppDevice::hashToken($validated['token']),
                ],
                [
                    'token' => $validated['token'],
                    'platform' => $validated['platform'],
                    'locale' => $validated['locale'] ?? null,
                    'shell_version' => $validated['shellVersion'] ?? null,
                    'last_seen_at' => now(),
                ],
            );
        }

        return response()->json(null, 204);
    }
}
