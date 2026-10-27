<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AcceptInviteRequest;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Resources\OrganizationResource;
use App\Http\Resources\UserResource;
use App\Models\Organization;
use App\Models\OrganizationInvite;
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
                'role' => 'owner',
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

            throw ValidationException::withMessages([
                'email' => ['Those credentials do not match our records.'],
            ]);
        }

        RateLimiter::clear($this->throttleKey($request));

        return $this->session($user, $user->organization);
    }

    public function invite(string $token): JsonResponse
    {
        $invite = $this->openInvite($token);

        return response()->json([
            'data' => [
                'organizationName' => $invite->organization->name,
                'email' => $invite->email,
                'role' => $invite->role,
            ],
        ]);
    }

    public function acceptInvite(AcceptInviteRequest $request, string $token): JsonResponse
    {
        $invite = $this->openInvite($token);

        // Another organisation can hold an open invite for the same address,
        // and only the first one accepted can have it: users.email is unique
        // product-wide. Without this the insert would fail as a 500.
        if (User::where('email', $invite->email)->exists()) {
            throw ValidationException::withMessages([
                'email' => ['That email address already has an AppForge account. Sign in instead.'],
            ]);
        }

        $user = DB::transaction(function () use ($invite, $request) {
            $user = $invite->organization->users()->create([
                'name' => trim($request->validated('name')),
                'email' => $invite->email,
                'password' => $request->validated('password'),
                'role' => $invite->role,
            ]);

            $invite->update(['accepted_at' => now()]);

            return $user;
        });

        return $this->session($user, $invite->organization, 201);
    }

    public function logout(Request $request): JsonResponse
    {

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

    private function openInvite(string $token): OrganizationInvite
    {
        $invite = OrganizationInvite::where('token_hash', OrganizationInvite::hash($token))->first();

        abort_if($invite === null, 404, 'That invitation link is not valid.');

        abort_unless(
            $invite->isOpen(),
            404,
            'That invitation has expired or has already been used. Ask for a new one.',
        );

        return $invite;
    }

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
