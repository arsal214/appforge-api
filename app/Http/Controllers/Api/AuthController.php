<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Resources\OrganizationResource;
use App\Http\Resources\UserResource;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Signing up creates the organisation and its first user together. There is
     * no path to a user without an organisation — every query in this API is
     * scoped by one, and a user who has none would be a permanent special case.
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        [$user, $organization] = DB::transaction(function () use ($request) {
            $organization = Organization::create([
                'name' => trim($request->validated('organizationName')),
                'slug' => Str::slug($request->validated('organizationName')),
            ]);

            $user = $organization->users()->create([
                'name' => trim($request->validated('name')),
                'email' => $request->validated('email'),
                'password' => $request->validated('password'),
            ]);

            return [$user, $organization];
        });

        return $this->session($user, $organization, 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $this->ensureNotRateLimited($request);

        $email = $request->validated('email');
        $user = User::where('email', $email)->first();

        if (! $user || ! Hash::check($request->validated('password'), $user->password)) {
            RateLimiter::hit($this->throttleKey($request));

            // One message for both branches. Distinguishing "no such account"
            // from "wrong password" turns the login form into an oracle for
            // which of our customers' staff emails are registered.
            throw ValidationException::withMessages([
                'email' => ['Those credentials do not match our records.'],
            ]);
        }

        RateLimiter::clear($this->throttleKey($request));

        return $this->session($user, $user->organization);
    }

    public function logout(Request $request): JsonResponse
    {
        // The token used to make *this* request, not every token the user has.
        // Logging out of a laptop must not sign them out on their phone.
        $request->user()->currentAccessToken()->delete();

        return response()->json(['data' => null], 200);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'data' => [
                'user' => new UserResource($user),
                'organization' => new OrganizationResource($user->organization),
            ],
        ]);
    }

    private function session(User $user, Organization $organization, int $status = 200): JsonResponse
    {
        return response()->json([
            'data' => [
                'token' => $user->createToken('panel')->plainTextToken,
                'user' => new UserResource($user),
                'organization' => new OrganizationResource($organization),
            ],
        ], $status);
    }

    /**
     * Per-email-and-IP throttling on top of the route's blanket limit. The
     * route limit alone lets an attacker spread guesses for one account across
     * many addresses; this one follows the account being attacked.
     */
    private function ensureNotRateLimited(LoginRequest $request): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey($request), 5)) {
            return;
        }

        $seconds = RateLimiter::availableIn($this->throttleKey($request));

        throw ValidationException::withMessages([
            'email' => ["Too many attempts. Try again in {$seconds} seconds."],
        ])->status(429);
    }

    private function throttleKey(LoginRequest $request): string
    {
        return 'login|'.Str::transliterate((string) $request->input('email')).'|'.$request->ip();
    }
}
