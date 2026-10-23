<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppDevice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

        $exists = DB::table('apps')
            ->where('id', $app)
            ->whereNull('deleted_at')
            ->exists();

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
