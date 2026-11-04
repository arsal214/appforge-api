<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TokenExpiryTest extends TestCase
{
    use RefreshDatabase;

    private function signIn(User $user): string
    {
        return $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk()->json('data.token');
    }

    private function asToken(string $token, string $path = '/api/apps')
    {
        return $this->withHeader('Authorization', "Bearer {$token}")->getJson($path);
    }

    #[Test]
    public function a_new_token_is_given_an_expiry(): void
    {
        $user = User::factory()->create();
        $this->signIn($user);

        $row = PersonalAccessToken::firstOrFail();

        $this->assertNotNull(
            $row->expires_at,
            'a token was handed out that never expires',
        );
        $this->assertTrue(
            $row->expires_at->isFuture(),
            'the new token was already expired',
        );
    }

    #[Test]
    public function an_expired_token_is_refused(): void
    {
        $user = User::factory()->create();
        $token = $this->signIn($user);

        $this->asToken($token)->assertOk();

        PersonalAccessToken::query()->update(['expires_at' => now()->subMinute()]);
        $this->forgetAuthentication();

        $this->asToken($token)->assertUnauthorized();
    }

    #[Test]
    public function using_the_panel_pushes_the_expiry_forward(): void
    {
        $user = User::factory()->create();
        $token = $this->signIn($user);

        PersonalAccessToken::query()->update(['expires_at' => now()->addMinutes(5)]);
        $before = PersonalAccessToken::firstOrFail()->expires_at;

        $this->forgetAuthentication();
        $this->asToken($token)->assertOk();

        $after = PersonalAccessToken::firstOrFail()->expires_at;

        $this->assertTrue(
            $after->gt($before),
            'the expiry did not move, so an active customer would be signed out',
        );
        $this->assertTrue($after->isFuture());
    }

    #[Test]
    public function the_token_string_does_not_change_when_the_expiry_slides(): void
    {
        $user = User::factory()->create();
        $token = $this->signIn($user);

        PersonalAccessToken::query()->update(['expires_at' => now()->addMinutes(5)]);
        $this->forgetAuthentication();

        $this->asToken($token)->assertOk();
        $this->forgetAuthentication();

        $this->asToken($token)->assertOk();

        $this->assertSame(1, PersonalAccessToken::count(), 'a second token appeared');
    }

    #[Test]
    public function a_session_already_fresh_is_not_written_to_on_every_request(): void
    {
        $user = User::factory()->create();
        $token = $this->signIn($user);

        $first = PersonalAccessToken::firstOrFail()->expires_at;

        $this->forgetAuthentication();
        $this->asToken($token)->assertOk();

        $this->assertTrue(
            PersonalAccessToken::firstOrFail()->expires_at->equalTo($first),
            'the expiry was rewritten despite having barely moved',
        );
    }

    #[Test]
    public function the_policy_can_be_turned_off(): void
    {
        config(['sanctum.idle_minutes' => 0]);

        $user = User::factory()->create();
        $this->signIn($user);

        $this->assertNull(
            PersonalAccessToken::firstOrFail()->expires_at,
            'a zero window still put an expiry on the token',
        );
    }

    #[Test]
    public function a_password_reset_still_kills_every_token(): void
    {
        $user = User::factory()->create();
        $token = $this->signIn($user);

        $this->asToken($token)->assertOk();

        $user->tokens()->delete();
        $this->forgetAuthentication();

        $this->asToken($token)->assertUnauthorized();
    }

    private function backfill(): void
    {
        $migration = require database_path(
            'migrations/2026_11_04_090000_expire_tokens_that_never_could.php',
        );

        $migration->up();
    }

    #[Test]
    public function a_token_left_over_from_before_the_policy_stops_working(): void
    {
        $user = User::factory()->create();
        $token = $this->signIn($user);

        DB::table('personal_access_tokens')->update([
            'expires_at' => null,
            'last_used_at' => now()->subDays(30),
        ]);

        $this->backfill();

        $expires = PersonalAccessToken::firstOrFail()->expires_at;

        $this->assertNotNull($expires, 'the old token still never expires');
        $this->assertTrue(
            $expires->isPast(),
            'the backfill handed an abandoned token a fresh window',
        );

        $this->forgetAuthentication();
        $this->asToken($token)->assertUnauthorized();
    }

    #[Test]
    public function a_token_still_in_use_survives_the_backfill(): void
    {
        $user = User::factory()->create();
        $token = $this->signIn($user);

        DB::table('personal_access_tokens')->update([
            'expires_at' => null,
            'last_used_at' => now()->subMinutes(10),
        ]);

        $this->backfill();

        $this->forgetAuthentication();
        $this->asToken($token)->assertOk();
    }
}
