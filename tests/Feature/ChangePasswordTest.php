<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ChangePasswordTest extends TestCase
{
    use RefreshDatabase;

    private function signIn(User $user, string $password): string
    {
        return $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => $password,
        ])->assertOk()->json('data.token');
    }

    #[Test]
    public function someone_signed_in_sets_a_new_password_with_their_old_one(): void
    {
        $user = User::factory()->create(['password' => 'the-old-password']);

        $here = $this->signIn($user, 'the-old-password');
        $elsewhere = $this->signIn($user, 'the-old-password');

        $this->withToken($here)
            ->putJson('/api/account/password', [
                'currentPassword' => 'the-old-password',
                'password' => 'the-new-password',
            ])->assertOk();

        // This device carries on; the other one is signed out.
        $this->withToken($here)->getJson('/api/apps')->assertOk();
        $this->assertSame(1, DB::table('personal_access_tokens')
            ->where('tokenable_id', $user->getKey())->count());

        $this->forgetAuthentication();
        $this->withToken($elsewhere)->getJson('/api/apps')->assertUnauthorized();

        $this->forgetAuthentication();
        $this->withToken('')->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'the-old-password',
        ])->assertStatus(422);

        $this->signIn($user, 'the-new-password');

        $this->assertDatabaseHas('account_events', [
            'action' => 'account.password',
            'actor_id' => $user->getKey(),
        ]);
    }

    #[Test]
    public function the_wrong_current_password_changes_nothing(): void
    {
        $user = User::factory()->create(['password' => 'the-old-password']);

        $this->actingAs($user)
            ->putJson('/api/account/password', [
                'currentPassword' => 'a-guess',
                'password' => 'the-new-password',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('currentPassword');

        $this->signIn($user, 'the-old-password');
    }

    #[Test]
    public function the_new_password_has_the_usual_floor_and_needs_a_session(): void
    {
        $user = User::factory()->create(['password' => 'the-old-password']);

        $this->putJson('/api/account/password', [
            'currentPassword' => 'the-old-password',
            'password' => 'the-new-password',
        ])->assertUnauthorized();

        $this->actingAs($user)
            ->putJson('/api/account/password', [
                'currentPassword' => 'the-old-password',
                'password' => 'short',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');
    }
}
