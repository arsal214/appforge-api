<?php

namespace Tests\Feature;

use App\Models\App;
use App\Models\Organization;
use App\Models\OrganizationInvite;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TeamTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function registering_makes_you_the_owner(): void
    {
        $this->postJson('/api/auth/register', [
            'name' => 'Sam',
            'organizationName' => 'Sams Pizza',
            'email' => 'sam@example.com',
            'password' => 'correct horse battery',
        ])
            ->assertCreated()
            ->assertJsonPath('data.user.role', 'owner');
    }

    #[Test]
    public function the_team_starts_as_just_the_owner(): void
    {
        $user = User::factory()->create();

        $data = $this->actingAs($user)->getJson('/api/team')->assertOk()->json('data');

        $this->assertSame([$user->id], array_column($data['members'], 'id'));
        $this->assertSame([], $data['invites']);
    }

    #[Test]
    public function inviting_someone_returns_the_link_token_once(): void
    {
        $user = User::factory()->create();

        $data = $this->actingAs($user)->postJson('/api/team/invites', [
            'email' => 'Cook@Example.com',
            'role' => 'staff',
        ])->assertCreated()->json('data');

        $this->assertSame('cook@example.com', $data['invite']['email']);
        $this->assertSame('staff', $data['invite']['role']);
        $this->assertFalse($data['invite']['expired']);
        $this->assertSame(48, strlen($data['token']));

        // Only the hash is kept, so the token cannot come back from the listing.
        $this->assertDatabaseHas('organization_invites', [
            'email' => 'cook@example.com',
            'token_hash' => OrganizationInvite::hash($data['token']),
        ]);

        $listed = $this->actingAs($user)->getJson('/api/team')->assertOk()->json('data.invites');
        $this->assertCount(1, $listed);
        $this->assertArrayNotHasKey('token', $listed[0]);
        $this->assertArrayNotHasKey('tokenHash', $listed[0]);
    }

    #[Test]
    public function an_invite_cannot_be_made_an_owner(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/team/invites', [
            'email' => 'second@example.com',
            'role' => 'owner',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['role']);
    }

    #[Test]
    public function inviting_an_address_that_already_has_an_account_is_refused(): void
    {
        $user = User::factory()->create();
        User::factory()->create(['email' => 'taken@example.com']);

        $this->actingAs($user)->postJson('/api/team/invites', [
            'email' => 'taken@example.com',
            'role' => 'editor',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    #[Test]
    public function inviting_the_same_person_again_reissues_one_invite(): void
    {
        $user = User::factory()->create();

        $first = $this->actingAs($user)->postJson('/api/team/invites', [
            'email' => 'cook@example.com', 'role' => 'staff',
        ])->assertCreated()->json('data.token');

        $second = $this->actingAs($user)->postJson('/api/team/invites', [
            'email' => 'cook@example.com', 'role' => 'editor',
        ])->assertCreated()->json('data.token');

        $this->assertNotSame($first, $second);
        $this->assertSame(1, OrganizationInvite::where('email', 'cook@example.com')->count());

        $this->getJson("/api/auth/invites/{$first}")->assertNotFound();
        $this->getJson("/api/auth/invites/{$second}")
            ->assertOk()
            ->assertJsonPath('data.role', 'editor');
    }

    #[Test]
    public function an_invite_can_be_looked_up_and_accepted(): void
    {
        $owner = User::factory()->create();
        $token = $this->actingAs($owner)->postJson('/api/team/invites', [
            'email' => 'cook@example.com', 'role' => 'staff',
        ])->assertCreated()->json('data.token');

        $this->getJson("/api/auth/invites/{$token}")
            ->assertOk()
            ->assertJsonPath('data.email', 'cook@example.com')
            ->assertJsonPath('data.role', 'staff')
            ->assertJsonPath('data.organizationName', $owner->organization->name);

        $data = $this->postJson("/api/auth/invites/{$token}", [
            'name' => 'The Cook',
            'password' => 'correct horse battery',
        ])->assertCreated()->json('data');

        $this->assertSame('staff', $data['user']['role']);
        $this->assertSame($owner->organization_id, $data['user']['organizationId']);
        $this->assertNotEmpty($data['token']);

        // And the invite is spent.
        $this->getJson("/api/auth/invites/{$token}")->assertNotFound();
        $this->assertSame([], $this->actingAs($owner)->getJson('/api/team')->json('data.invites'));
    }

    #[Test]
    public function an_expired_or_unknown_invite_is_refused(): void
    {
        $owner = User::factory()->create();

        $owner->organization->invites()->create([
            'email' => 'late@example.com',
            'role' => 'staff',
            'token_hash' => OrganizationInvite::hash('stale-token'),
            'expires_at' => now()->subDay(),
        ]);

        $this->getJson('/api/auth/invites/stale-token')->assertNotFound();
        $this->postJson('/api/auth/invites/stale-token', [
            'name' => 'Too Late', 'password' => 'correct horse battery',
        ])->assertNotFound();

        $this->getJson('/api/auth/invites/never-existed')->assertNotFound();
    }

    #[Test]
    public function an_invite_can_be_revoked_and_another_organisations_cannot(): void
    {
        $owner = User::factory()->create();
        $token = $this->actingAs($owner)->postJson('/api/team/invites', [
            'email' => 'cook@example.com', 'role' => 'staff',
        ])->json('data.token');

        $invite = OrganizationInvite::where('email', 'cook@example.com')->firstOrFail();

        $stranger = User::factory()->create();
        $this->actingAs($stranger)->deleteJson("/api/team/invites/{$invite->id}")->assertNotFound();
        $this->assertDatabaseHas('organization_invites', ['id' => $invite->id]);

        $this->actingAs($owner)->deleteJson("/api/team/invites/{$invite->id}")->assertOk();
        $this->assertDatabaseMissing('organization_invites', ['id' => $invite->id]);
        $this->getJson("/api/auth/invites/{$token}")->assertNotFound();
    }

    #[Test]
    public function a_members_role_can_be_changed_but_the_owners_cannot(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create([
            'organization_id' => $owner->organization_id,
            'role' => 'staff',
        ]);

        $this->actingAs($owner)
            ->patchJson("/api/team/members/{$member->id}", ['role' => 'editor'])
            ->assertOk()
            ->assertJsonPath('data.role', 'editor');

        $this->actingAs($owner)
            ->patchJson("/api/team/members/{$owner->id}", ['role' => 'staff'])
            ->assertStatus(422);

        $this->assertSame('owner', $owner->fresh()->role);
    }

    #[Test]
    public function removing_a_member_ends_their_session(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create([
            'organization_id' => $owner->organization_id,
            'role' => 'editor',
        ]);

        $token = $member->createToken('panel')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/apps')->assertOk();

        $this->actingAs($owner)->deleteJson("/api/team/members/{$member->id}")->assertOk();

        $this->assertDatabaseMissing('users', ['id' => $member->id]);

        // Checked in the table rather than by replaying the request: actingAs()
        // above pins the authenticated user for the rest of this test, so a
        // bearer header would no longer be what answers.
        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $member->id,
        ]);
    }

    #[Test]
    public function another_organisations_member_is_invisible(): void
    {
        $owner = User::factory()->create();
        $other = Organization::factory()->create();
        $theirs = User::factory()->create(['organization_id' => $other->id, 'role' => 'editor']);

        $this->actingAs($owner)
            ->patchJson("/api/team/members/{$theirs->id}", ['role' => 'staff'])
            ->assertNotFound();

        $this->actingAs($owner)
            ->deleteJson("/api/team/members/{$theirs->id}")
            ->assertNotFound();

        $this->assertSame('editor', $theirs->fresh()->role);
    }

    #[Test]
    public function an_editor_can_build_but_not_touch_the_team(): void
    {
        $owner = User::factory()->create();
        $editor = User::factory()->create([
            'organization_id' => $owner->organization_id,
            'role' => 'editor',
        ]);

        $this->actingAs($editor)->postJson('/api/apps', [
            'name' => 'Editor App',
            'templateId' => 'tpl_restaurant',
            'sourceUrl' => null,
        ])->assertCreated();

        $this->actingAs($editor)->getJson('/api/team')->assertForbidden();
        $this->actingAs($editor)->postJson('/api/team/invites', [
            'email' => 'nope@example.com', 'role' => 'staff',
        ])->assertForbidden();
        $this->actingAs($editor)->patchJson('/api/organization', ['name' => 'Renamed'])->assertForbidden();
    }

    #[Test]
    public function a_staff_member_can_read_an_app_but_not_change_it(): void
    {
        $owner = User::factory()->create();
        $staff = User::factory()->create([
            'organization_id' => $owner->organization_id,
            'role' => 'staff',
        ]);
        $app = App::factory()->create(['organization_id' => $owner->organization_id]);

        $this->actingAs($staff)->getJson('/api/apps')->assertOk();
        $this->actingAs($staff)->getJson("/api/apps/{$app->id}")->assertOk();
        $this->actingAs($staff)->getJson("/api/apps/{$app->id}/config")->assertOk();
        $this->actingAs($staff)->getJson("/api/apps/{$app->id}/orders")->assertOk();

        $this->actingAs($staff)
            ->putJson("/api/apps/{$app->id}/config", ['config' => []])
            ->assertForbidden();

        $this->actingAs($staff)->postJson('/api/apps', [
            'name' => 'Not Allowed',
            'templateId' => 'tpl_restaurant',
            'sourceUrl' => null,
        ])->assertForbidden();

        $this->actingAs($staff)->deleteJson("/api/apps/{$app->id}")->assertForbidden();
        $this->assertDatabaseHas('apps', ['id' => $app->id, 'deleted_at' => null]);

        $this->actingAs($staff)->postJson("/api/apps/{$app->id}/versions")->assertForbidden();
        $this->actingAs($staff)->getJson("/api/apps/{$app->id}/credentials")->assertForbidden();
    }

    #[Test]
    public function everyone_can_still_edit_their_own_account(): void
    {
        $owner = User::factory()->create();
        $staff = User::factory()->create([
            'organization_id' => $owner->organization_id,
            'role' => 'staff',
        ]);

        $this->actingAs($staff)->patchJson('/api/account', [
            'name' => 'Renamed Staff',
            'email' => 'renamed.staff@example.com',
        ])->assertOk()->assertJsonPath('data.role', 'staff');
    }

    #[Test]
    public function the_team_needs_a_login(): void
    {
        $this->getJson('/api/team')->assertUnauthorized();
        $this->postJson('/api/team/invites', ['email' => 'a@b.com', 'role' => 'staff'])
            ->assertUnauthorized();
    }
}
