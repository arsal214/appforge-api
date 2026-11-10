<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WaitlistSignup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WaitlistController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'websiteUrl' => ['required', 'string', 'url', 'max:512'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'source' => ['nullable', 'string', 'max:255'],
        ]);

        $changes = ['website_url' => $validated['websiteUrl']];

        if (isset($validated['source'])) {
            $changes['source'] = $validated['source'];
        }

        WaitlistSignup::updateOrCreate(['email' => $validated['email']], $changes);

        return response()->json(null, 204);
    }
}
