<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppDevice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * A phone telling us it can receive notifications.
 *
 * Unauthenticated, because the shell has no user to authenticate — and that
 * makes it the second-widest attack surface in the estate after the config
 * endpoint. What keeps it safe:
 *
 *  - **It is write-only and returns nothing.** No count, no id, no confirmation
 *    of whether the app exists. An attacker learns nothing by calling it, so it
 *    cannot be used to enumerate app ids.
 *  - **Idempotent per device.** Registering the same token twice updates one
 *    row rather than growing the table, so a retry loop in a shell cannot
 *    inflate an audience count or fill a disk.
 *  - **Rate limited** at the route.
 *
 * The residual risk is junk registrations from someone who knows an app id:
 * they inflate an audience number and waste send attempts, but they cannot read
 * anything or reach a real device. Closing that properly needs an attestation
 * (Play Integrity / App Attest), which is a real answer for later rather than
 * something to fake now.
 */
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

        // Not `findOrFail`: the response is identical either way, so a caller
        // cannot tell a real app id from a made-up one.
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

        // 204 regardless. There is nothing a phone can do with the difference,
        // and saying nothing is what stops this being an oracle.
        return response()->json(null, 204);
    }
}
