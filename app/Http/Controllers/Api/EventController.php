<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Analytics\EventRecorder;
use App\Support\PublicApp;
use AppForge\Schema\Contract;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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

        $exists = PublicApp::exists($app);

        if ($exists) {
            (new EventRecorder)->record(
                $app,
                $validated['platform'],
                $validated['events'],
            );
        }

        return response()->json(null, 204);
    }
}
