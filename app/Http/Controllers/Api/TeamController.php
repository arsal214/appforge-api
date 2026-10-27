<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreInviteRequest;
use App\Http\Resources\OrganizationInviteResource;
use App\Http\Resources\UserResource;
use App\Models\OrganizationInvite;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class TeamController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $organization = $request->user()->organization;

        return response()->json([
            'data' => [
                'members' => UserResource::collection(
                    $organization->users()->orderBy('created_at')->get()
                ),
                'invites' => OrganizationInviteResource::collection(
                    $organization->invites()->whereNull('accepted_at')->latest()->get()
                ),
            ],
        ]);
    }

    public function store(StoreInviteRequest $request): JsonResponse
    {
        $token = Str::random(48);

        $invite = $request->user()->organization->invites()->updateOrCreate(
            ['email' => $request->validated('email')],
            [
                'role' => $request->validated('role'),
                'token_hash' => OrganizationInvite::hash($token),
                'invited_by' => $request->user()->getKey(),
                'expires_at' => now()->addDays(OrganizationInvite::TTL_DAYS),
                'accepted_at' => null,
            ],
        );

        return response()->json([
            'data' => [
                'invite' => new OrganizationInviteResource($invite),

                // Returned once and never again: only the hash is kept, and
                // there is no mail pipeline here, so the owner passes the link
                // on themselves. Inviting the same address again reissues it.
                'token' => $token,
            ],
        ], 201);
    }

    public function destroy(Request $request, string $invite): JsonResponse
    {
        $request->user()->organization->invites()->findOrFail($invite)->delete();

        return response()->json(['data' => null]);
    }

    public function updateMember(Request $request, string $user): UserResource
    {
        $validated = $request->validate([
            'role' => ['required', Rule::in(array_diff(User::ROLES, ['owner']))],
        ]);

        $member = $this->member($request, $user);
        $member->update(['role' => $validated['role']]);

        return new UserResource($member);
    }

    public function destroyMember(Request $request, string $user): JsonResponse
    {
        $member = $this->member($request, $user);

        // Their tokens outlive the row otherwise: personal_access_tokens is a
        // polymorphic table with no foreign key, so a deleted member would keep
        // a working login until the token expired.
        $member->tokens()->delete();
        $member->delete();

        return response()->json(['data' => null]);
    }

    private function member(Request $request, string $id): User
    {
        $member = $request->user()->organization->users()->findOrFail($id);

        abort_if(
            $member->role === 'owner',
            422,
            'The owner cannot be changed or removed here. Ask us to transfer the account instead.',
        );

        return $member;
    }
}
