<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateAccountRequest;
use App\Http\Resources\UserResource;
use App\Support\Activity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class AccountController extends Controller
{
    /**
     * A new password for someone who is signed in and knows the old one.
     *
     * The old one is asked for because a session is not proof of who is at
     * the keyboard: an unlocked laptop should not be enough to lock its owner
     * out. Every other device is signed out; this one carries on.
     */
    public function password(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'currentPassword' => ['required', 'string', 'max:200'],
            'password' => ['required', 'string', Password::min(8), 'max:200'],
        ], [
            'currentPassword.*' => 'Enter your current password.',
            'password.*' => 'Use at least 8 characters.',
        ]);

        $user = $request->user();

        if (! Hash::check($validated['currentPassword'], $user->password)) {
            throw ValidationException::withMessages([
                'currentPassword' => ['That is not your current password.'],
            ]);
        }

        $user->forceFill(['password' => $validated['password']])->save();

        $here = $user->currentAccessToken();

        $user->tokens()
            ->when(
                $here instanceof PersonalAccessToken,
                fn ($tokens) => $tokens->whereKeyNot($here->getKey()),
            )
            ->delete();

        Activity::record($user, 'account.password', null, $user->getKey());

        return response()->json(['data' => null]);
    }

    public function update(UpdateAccountRequest $request): UserResource
    {
        $user = $request->user();

        $was = ['name' => $user->name, 'email' => $user->email];

        $user->update([
            'name' => trim($request->validated('name')),
            'email' => $request->validated('email'),
        ]);

        $changed = array_keys(array_diff_assoc(
            ['name' => $user->name, 'email' => $user->email],
            $was,
        ));

        if ($changed !== []) {
            Activity::record($user, 'account.details', null, $user->getKey(), [
                'changed' => $changed,
            ]);
        }

        return new UserResource($user);
    }
}
