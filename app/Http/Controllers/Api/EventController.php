<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Analytics\EventRecorder;
use AppForge\Schema\Contract;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * A phone reporting what happened inside it.
 *
 * Unauthenticated for the same reason the config and device endpoints are: the
 * shell has no user to authenticate. It follows the same rules as the device
 * endpoint, for the same reasons — write-only, 204 whether or not the app
 * exists so it cannot be used to enumerate app ids, and rate limited.
 *
 * Batched rather than one request per event. A phone that posted on every
 * screen change would be a chatty background process on someone's mobile data
 * allowance, and analytics is not worth a customer's battery.
 *
 * The counting rules — and the reasons the day is server-stamped and the detail
 * vocabulary is closed — live in EventRecorder.
 */
class EventController extends Controller
{
    public function store(Request $request, string $app): JsonResponse
    {
        $validated = $request->validate([
            'platform' => ['required', 'in:ios,android'],
            'events' => ['required', 'array', 'max:'.EventRecorder::MAX_BATCH],
            'events.*.name' => ['required', Rule::in(Contract::vocabulary('eventNames'))],
            'events.*.detail' => ['nullable', 'string', 'max:64'],
            'events.*.count' => ['nullable', 'integer', 'min:1', 'max:'.EventRecorder::MAX_COUNT],
        ]);

        // Query builder, not the App model: `App` carries OrganizationScope,
        // which fails closed with no authenticated user and would make this
        // 404 for every app in existence. Same reason PublicConfigController
        // does it this way.
        $exists = DB::table('apps')
            ->where('id', $app)
            ->whereNull('deleted_at')
            ->exists();

        if ($exists) {
            (new EventRecorder)->record(
                $app,
                $validated['platform'],
                $validated['events'],
            );
        }

        // 204 regardless, and no count of what was accepted. A shell cannot act
        // on the difference, and saying nothing is what stops this being an
        // oracle for which app ids are real.
        return response()->json(null, 204);
    }
}
