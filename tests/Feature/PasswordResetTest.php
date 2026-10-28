<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private function token(User $user): string
    {
        Notification::fake();

        $this->postJson('/api/auth/forgot-password', ['email' => $user->email])
            ->assertOk();

        $captured = null;

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use (&$captured) {
            $captured = $notification->token;

            return true;
        });

        $this->assertIsString($captured);

        return $captured;
    }

    #[Test]
    public function asking_for_a_link_sends_one(): void
    {
        $user = User::factory()->create(['email' => 'sam@example.com']);

        Notification::fake();

        $this->postJson('/api/auth/forgot-password', ['email' => 'Sam@Example.com'])
            ->assertOk();

        Notification::assertSentTo($user, ResetPassword::class);
        $this->assertDatabaseHas('password_reset_tokens', ['email' => 'sam@example.com']);
    }

    #[Test]
    public function the_link_points_at_the_panel_and_not_at_this_api(): void
    {
        $user = User::factory()->create(['email' => 'sam@example.com']);

        Notification::fake();
        $this->postJson('/api/auth/forgot-password', ['email' => $user->email])->assertOk();

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
            $url = $notification->toMail($user)->actionUrl;

            $this->assertStringStartsWith(config('app.frontend_url').'/reset-password?', $url);
            $this->assertStringContainsString('token='.$notification->token, $url);
            $this->assertStringContainsString('email=sam%40example.com', $url);

            return true;
        });
    }

    #[Test]
    public function an_unknown_address_answers_exactly_the_same(): void
    {
        Notification::fake();

        $known = User::factory()->create(['email' => 'known@example.com']);

        $first = $this->postJson('/api/auth/forgot-password', ['email' => 'known@example.com']);
        $second = $this->postJson('/api/auth/forgot-password', ['email' => 'nobody@example.com']);

        $this->assertSame($first->status(), $second->status());
        $this->assertSame($first->getContent(), $second->getContent());

        Notification::assertSentTo($known, ResetPassword::class);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => 'nobody@example.com']);
    }

    #[Test]
    public function a_junk_address_is_refused(): void
    {
        $this->postJson('/api/auth/forgot-password', ['email' => 'not-an-email'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    #[Test]
    public function the_link_sets_a_new_password(): void
    {
        $user = User::factory()->create(['email' => 'sam@example.com']);
        $token = $this->token($user);

        $this->postJson('/api/auth/reset-password', [
            'token' => $token,
            'email' => 'sam@example.com',
            'password' => 'correct horse battery',
        ])->assertOk();

        $this->assertTrue(Hash::check('correct horse battery', $user->fresh()->password));

        $this->postJson('/api/auth/login', [
            'email' => 'sam@example.com',
            'password' => 'correct horse battery',
        ])->assertOk();
    }

    #[Test]
    public function resetting_signs_every_device_out(): void
    {
        $user = User::factory()->create(['email' => 'sam@example.com']);
        $token = $this->token($user);

        $user->createToken('phone');
        $user->createToken('laptop');
        $this->assertDatabaseCount('personal_access_tokens', 2);

        $this->postJson('/api/auth/reset-password', [
            'token' => $token,
            'email' => 'sam@example.com',
            'password' => 'correct horse battery',
        ])->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    #[Test]
    public function a_link_cannot_be_used_twice(): void
    {
        $user = User::factory()->create(['email' => 'sam@example.com']);
        $token = $this->token($user);

        $body = [
            'token' => $token,
            'email' => 'sam@example.com',
            'password' => 'correct horse battery',
        ];

        $this->postJson('/api/auth/reset-password', $body)->assertOk();

        $this->postJson('/api/auth/reset-password', [...$body, 'password' => 'another password here'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['token']);

        $this->assertTrue(Hash::check('correct horse battery', $user->fresh()->password));
    }

    #[Test]
    public function a_made_up_token_is_refused(): void
    {
        User::factory()->create(['email' => 'sam@example.com']);

        $this->postJson('/api/auth/reset-password', [
            'token' => 'not-a-real-token',
            'email' => 'sam@example.com',
            'password' => 'correct horse battery',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['token']);
    }

    #[Test]
    public function a_token_issued_for_someone_else_is_refused(): void
    {
        $sam = User::factory()->create(['email' => 'sam@example.com']);
        $rival = User::factory()->create(['email' => 'rival@example.com']);

        $token = $this->token($sam);

        $this->postJson('/api/auth/reset-password', [
            'token' => $token,
            'email' => 'rival@example.com',
            'password' => 'correct horse battery',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['token']);

        $this->assertFalse(Hash::check('correct horse battery', $rival->fresh()->password));
    }

    #[Test]
    public function a_short_password_is_refused_and_the_link_survives(): void
    {
        $user = User::factory()->create(['email' => 'sam@example.com']);
        $token = $this->token($user);

        $this->postJson('/api/auth/reset-password', [
            'token' => $token,
            'email' => 'sam@example.com',
            'password' => 'short',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password']);

        $this->postJson('/api/auth/reset-password', [
            'token' => $token,
            'email' => 'sam@example.com',
            'password' => 'correct horse battery',
        ])->assertOk();
    }

    #[Test]
    public function the_support_command_unlocks_an_account(): void
    {
        $user = User::factory()->create(['email' => 'stuck@example.com']);
        $user->createToken('phone');

        $this->artisan('appforge:reset-password', ['email' => 'Stuck@Example.com'])
            ->assertExitCode(0);

        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertNotSame($user->password, $user->fresh()->password);

        $this->artisan('appforge:reset-password', ['email' => 'nobody@example.com'])
            ->assertExitCode(1);
    }

    #[Test]
    public function the_support_command_can_hand_over_a_link_instead(): void
    {
        $user = User::factory()->create(['email' => 'stuck@example.com']);
        $before = $user->password;

        $this->artisan('appforge:reset-password', ['email' => 'stuck@example.com', '--link' => true])
            ->assertExitCode(0);

        $this->assertSame($before, $user->fresh()->password, 'the link option changes nothing yet');
        $this->assertDatabaseHas('password_reset_tokens', ['email' => 'stuck@example.com']);
    }
}
