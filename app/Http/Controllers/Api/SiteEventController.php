<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Analytics\SiteEventRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SiteEventController extends Controller
{
    public function store(Request $request, SiteEventRecorder $recorder): JsonResponse
    {
        $validated = $request->validate([
            'event' => ['required', 'string', Rule::in(SiteEventRecorder::EVENTS)],
        ]);

        $recorder->record($validated['event']);

        return response()->json(null, 204);
    }
}
