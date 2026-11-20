<?php

namespace Tests\Feature;

use App\Mail\AppPasswordCode;
use App\Models\App;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AppPasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private App $shop;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->shop = App::factory()->create(['name' => 'Sams Pizza']);
    }

    private function join(string $email = 'ali@example.com'): string
    {
        return $this->postJson("/api/public/apps/{$this->shop->id}/users", [
            'name' => 'Ali',
            'email' => $email,
            'password' => 'the-old-password',
        ])->assertCreated()->json('data.token');
    }

    private function forgot(string $email = 'ali@example.com', ?App $app = null): void
    {
        $id = ($app ?? $this->shop)->id;

        $this->postJson("/api/public/apps/{$id}/password-resets", ['email' => $email])
            ->assertStatus(202);
    }

    /** The code in the newest email, the way the customer would read it. */
    private function lastCode(): string
    {
        $sent = Mail::queued(AppPasswordCode::class);
        $this->assertNotEmpty($sent, 'no code was emailed');

        return $sent->last()->code;
    }

    private function confirm(string $code, string $password = 'the-new-password', ?App $app = null)
    {
        $id = ($app ?? $this->shop)->id;

        // Each try in its own minute, clear of the per-email rate limit,
        // so what is tested is the code rule and not the throttle.
        $this->travel(61)->seconds();

        return $this->postJson("/api/public/apps/{$id}/password-resets/confirm", [
            'email' => 'ali@example.com',
            'code' => $code,
            'password' => $password,
        ]);
    }

    private function wrong(string $code): string
    {
        return $code === '000000' ? '111111' : '000000';
    }

    #[Test]
    public function a_customer_who_forgot_their_password_sets_a_new_one_with_the_emailed_code(): void
    {
        $old = $this->join();
        $this->forgot();

        Mail::assertQueued(
            AppPasswordCode::class,
            fn (AppPasswordCode $mail) => $mail->hasTo('ali@example.com')
                && $mail->appName === 'Sams Pizza'
                && preg_match('/^\d{6}$/', $mail->code) === 1,
        );

        $code = $this->lastCode();

        // Typed with a space in the middle, the way it is often copied.
        $token = $this->confirm(substr($code, 0, 3).' '.substr($code, 3))
            ->assertOk()
            ->assertJsonPath('data.user.email', 'ali@example.com')
            ->json('data.token');

        $this->assertNotEmpty($token);

        // Every other phone is signed out; this one is signed in.
        $this->withHeader('Authorization', "Bearer {$old}")
            ->getJson("/api/public/apps/{$this->shop->id}/me")
            ->assertUnauthorized();
        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/public/apps/{$this->shop->id}/me")
            ->assertOk();

        $this->travel(61)->seconds();
        $this->withHeaders(['Authorization' => ''])
            ->postJson("/api/public/apps/{$this->shop->id}/sessions", [
                'email' => 'ali@example.com',
                'password' => 'the-old-password',
            ])->assertStatus(422);

        $this->travel(61)->seconds();
        $this->postJson("/api/public/apps/{$this->shop->id}/sessions", [
            'email' => 'ali@example.com',
            'password' => 'the-new-password',
        ])->assertOk();
    }

    #[Test]
    public function an_unknown_email_gets_the_same_answer_and_no_email(): void
    {
        $this->join();
        $this->forgot('nobody@example.com');

        Mail::assertNothingQueued();
    }

    #[Test]
    public function a_code_works_once(): void
    {
        $this->join();
        $this->forgot();
        $code = $this->lastCode();

        $this->confirm($code)->assertOk();
        $this->confirm($code, 'a-third-password')
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');
    }

    #[Test]
    public function five_wrong_guesses_use_the_code_up(): void
    {
        $this->join();
        $this->forgot();
        $code = $this->lastCode();

        foreach (range(1, 5) as $guess) {
            $this->confirm($this->wrong($code))->assertStatus(422)->assertJsonValidationErrors('code');
        }

        $this->confirm($code)->assertStatus(422);
    }

    #[Test]
    public function a_code_runs_out_after_fifteen_minutes(): void
    {
        $this->join();
        $this->forgot();
        $code = $this->lastCode();

        $this->travel(15)->minutes();

        $this->confirm($code)->assertStatus(422)->assertJsonValidationErrors('code');
    }

    #[Test]
    public function asking_twice_at_once_sends_one_email_and_a_new_code_replaces_the_old(): void
    {
        $this->join();
        $this->forgot();
        $this->forgot();

        Mail::assertQueuedCount(1);
        $first = $this->lastCode();

        $this->travel(2)->minutes();
        $this->forgot();

        Mail::assertQueuedCount(2);
        $second = $this->lastCode();

        if ($first !== $second) {
            $this->confirm($first)->assertStatus(422);
        }
        $this->confirm($second)->assertOk();
    }

    #[Test]
    public function no_more_than_five_codes_an_hour(): void
    {
        $this->join();

        foreach (range(1, 7) as $ask) {
            $this->forgot();
            $this->travel(2)->minutes();
        }

        Mail::assertQueuedCount(5);
    }

    #[Test]
    public function a_code_from_one_shop_does_nothing_at_another(): void
    {
        $this->join();
        $other = App::factory()->create();
        $this->postJson("/api/public/apps/{$other->id}/users", [
            'name' => 'Ali',
            'email' => 'ali@example.com',
            'password' => 'the-other-password',
        ])->assertCreated();

        $this->forgot();
        $code = $this->lastCode();

        $this->confirm($code, 'the-new-password', $other)->assertStatus(422);
        $this->confirm($code)->assertOk();
    }

    #[Test]
    public function a_binned_app_sends_no_codes(): void
    {
        $this->join();
        $this->shop->delete();

        $this->postJson("/api/public/apps/{$this->shop->id}/password-resets", ['email' => 'ali@example.com'])
            ->assertNotFound();

        Mail::assertNothingQueued();
    }

    #[Test]
    public function the_new_password_has_the_same_floor_as_signing_up(): void
    {
        $this->join();
        $this->forgot();

        $this->confirm($this->lastCode(), 'short')
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');
    }

    #[Test]
    public function the_email_shows_the_shops_name_and_the_code(): void
    {
        $body = (new AppPasswordCode('Sams [Pizza](https://evil.example)', '042917', 15))->render();

        $this->assertStringContainsString('042917', $body);
        $this->assertStringContainsString('15 minutes', $body);
        $this->assertStringNotContainsString('href="https://evil.example"', $body);
    }
}
