<?php

namespace Tests\Feature;

use App\Models\App;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AppUsersTest extends TestCase
{
    #[Test]
    public function the_app_id_and_the_email_have_to_be_spelled_exactly(): void
    {
        $app = App::factory()->create();

        $this->postJson("/api/public/apps/{$app->id}/users", [
            'name' => 'Ali',
            'email' => 'ali@example.com',
            'password' => 'a-long-enough-password',
        ])->assertCreated();

        // Each spelling the collation calls equal would be a rate-limit
        // bucket of its own, so each has to be a stranger instead.
        foreach ([strtoupper($app->id), $app->id.' '] as $spelling) {
            $this->postJson('/api/public/apps/'.rawurlencode($spelling).'/sessions', [
                'email' => 'ali@example.com',
                'password' => 'a-long-enough-password',
            ])->assertNotFound();
        }

        $this->postJson("/api/public/apps/{$app->id}/sessions", [
            'email' => "\u{e0}li@example.com",
            'password' => 'a-long-enough-password',
        ])->assertStatus(422)->assertJsonValidationErrors('email');

        $this->postJson("/api/public/apps/{$app->id}/sessions", [
            'email' => 'ali@example.com',
            'password' => 'a-long-enough-password',
        ])->assertOk();
    }

    #[Test]
    public function a_binned_app_no_longer_answers_its_customers(): void
    {
        $app = App::factory()->create();

        $token = $this->postJson("/api/public/apps/{$app->id}/users", [
            'name' => 'Ali',
            'email' => 'ali@example.com',
            'password' => 'a-long-enough-password',
        ])->assertCreated()->json('data.token');

        $app->delete();

        foreach (['me', 'me/orders'] as $path) {
            $this->withHeader('Authorization', "Bearer {$token}")
                ->getJson("/api/public/apps/{$app->id}/{$path}")
                ->assertNotFound();
        }
    }

    use RefreshDatabase;

    private const SAM = [
        'name' => 'Sam Carter',
        'email' => 'sam@example.com',
        'password' => 'a-long-enough-password',
    ];

    private function app(): App
    {
        $owner = User::factory()->create();

        return App::factory()->create(['organization_id' => $owner->organization_id]);
    }

    private function signUp(App $app, array $overrides = []): string
    {
        return $this->postJson("/api/public/apps/{$app->id}/users", array_replace(self::SAM, $overrides))
            ->assertCreated()
            ->json('data.token');
    }

    private function order(): array
    {
        return [
            'lines' => [['itemId' => 'i-1', 'title' => 'Margherita', 'price' => '12.00', 'quantity' => 1]],
            'total' => '12.00',
            'details' => [],
        ];
    }

    #[Test]
    public function someone_makes_an_account_and_is_signed_in(): void
    {
        $app = $this->app();

        $response = $this->postJson("/api/public/apps/{$app->id}/users", self::SAM)
            ->assertCreated()
            ->assertJsonPath('data.user.name', 'Sam Carter')
            ->assertJsonPath('data.user.email', 'sam@example.com');

        $this->assertStringNotContainsString('password', $response->getContent());

        $this->withToken($response->json('data.token'))
            ->getJson("/api/public/apps/{$app->id}/me")
            ->assertOk()
            ->assertJsonPath('data.user.email', 'sam@example.com');
    }

    #[Test]
    public function the_password_is_stored_hashed_and_the_token_is_too(): void
    {
        $app = $this->app();
        $token = $this->signUp($app);

        $this->assertNotSame(self::SAM['password'], DB::table('app_users')->value('password'));
        $this->assertSame(0, DB::table('app_user_tokens')->where('token_hash', $token)->count());
        $this->assertSame(1, DB::table('app_user_tokens')->where('token_hash', hash('sha256', $token))->count());
    }

    #[Test]
    public function one_email_is_one_account_per_app_and_a_different_one_in_the_next(): void
    {
        $app = $this->app();
        $this->signUp($app);

        $this->postJson("/api/public/apps/{$app->id}/users", ['email' => 'SAM@example.com'] + self::SAM)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->signUp($this->app());

        $this->assertSame(2, DB::table('app_users')->count());
    }

    #[Test]
    public function signing_in_needs_the_right_password_and_never_says_which_part_was_wrong(): void
    {
        $app = $this->app();
        $this->signUp($app);

        $this->postJson("/api/public/apps/{$app->id}/sessions", [
            'email' => 'sam@example.com',
            'password' => self::SAM['password'],
        ])->assertOk()->assertJsonStructure(['data' => ['token', 'user' => ['id', 'name', 'email']]]);

        $wrongPassword = $this->postJson("/api/public/apps/{$app->id}/sessions", [
            'email' => 'sam@example.com',
            'password' => 'not-the-password',
        ])->assertUnprocessable();

        $nobody = $this->postJson("/api/public/apps/{$app->id}/sessions", [
            'email' => 'nobody@example.com',
            'password' => 'not-the-password',
        ])->assertUnprocessable();

        $this->assertSame(
            $wrongPassword->json('errors.email'),
            $nobody->json('errors.email'),
            'the two refusals differ, which tells a stranger which emails have accounts',
        );
    }

    #[Test]
    public function an_account_in_one_app_is_a_stranger_in_another(): void
    {
        $token = $this->signUp($this->app());
        $other = $this->app();

        $this->withToken($token)->getJson("/api/public/apps/{$other->id}/me")->assertUnauthorized();

        $this->postJson("/api/public/apps/{$other->id}/sessions", [
            'email' => 'sam@example.com',
            'password' => self::SAM['password'],
        ])->assertUnprocessable();
    }

    #[Test]
    public function an_app_users_token_opens_nothing_in_the_panel(): void
    {
        $app = $this->app();
        $token = $this->signUp($app);

        $this->withToken($token)->getJson('/api/apps')->assertUnauthorized();
        $this->withToken($token)->getJson("/api/apps/{$app->id}/orders")->assertUnauthorized();
        $this->withToken($token)->getJson('/api/auth/me')->assertUnauthorized();
    }

    #[Test]
    public function signing_out_ends_that_token(): void
    {
        $app = $this->app();
        $token = $this->signUp($app);

        $this->withToken($token)->deleteJson("/api/public/apps/{$app->id}/sessions/current")->assertNoContent();
        $this->withToken($token)->getJson("/api/public/apps/{$app->id}/me")->assertUnauthorized();
    }

    #[Test]
    public function an_order_sent_while_signed_in_carries_who_sent_it(): void
    {
        $owner = User::factory()->create();
        $app = App::factory()->create(['organization_id' => $owner->organization_id]);
        $token = $this->signUp($app);

        $this->withToken($token)->postJson("/api/public/apps/{$app->id}/orders", $this->order())->assertCreated();
        $this->flushHeaders();
        $this->postJson("/api/public/apps/{$app->id}/orders", $this->order())->assertCreated();

        $orders = $this->actingAs($owner)
            ->getJson("/api/apps/{$app->id}/orders")
            ->assertOk()
            ->json('data.orders');

        $this->assertNull($orders[0]['customer']);
        $this->assertSame(['name' => 'Sam Carter', 'email' => 'sam@example.com'], $orders[1]['customer']);
    }

    #[Test]
    public function a_dead_token_does_not_cost_the_shop_the_order(): void
    {
        $app = $this->app();

        $this->withToken('a-token-nobody-issued')
            ->postJson("/api/public/apps/{$app->id}/orders", $this->order())
            ->assertCreated();

        $this->assertNull(DB::table('orders')->value('app_user_id'));
    }

    #[Test]
    public function someone_sees_what_they_sent_and_how_far_along_it_is(): void
    {
        $owner = User::factory()->create();
        $app = App::factory()->create(['organization_id' => $owner->organization_id]);
        $token = $this->signUp($app);

        $this->withToken($token)->postJson("/api/public/apps/{$app->id}/orders", $this->order())->assertCreated();
        $this->withToken($token)->postJson("/api/public/apps/{$app->id}/orders", $this->order())->assertCreated();

        $mine = $this->withToken($token)
            ->getJson("/api/public/apps/{$app->id}/me/orders")
            ->assertOk()
            ->json('data.orders');

        $this->assertSame([2, 1], array_column($mine, 'number'));
        $this->assertSame('new', $mine[0]['status']);
        $this->assertSame([['title' => 'Margherita', 'quantity' => 1]], $mine[0]['lines']);
        $this->assertEqualsCanonicalizing(
            ['number', 'kind', 'status', 'total', 'createdAt', 'lines'],
            array_keys($mine[0]),
        );

        $this->flushHeaders();
        $id = DB::table('orders')->where('app_id', $app->id)->where('number', 2)->value('id');
        $this->actingAs($owner)
            ->patchJson("/api/apps/{$app->id}/orders/{$id}", ['status' => 'confirmed'])
            ->assertOk();
        $this->forgetAuthentication();

        $this->withToken($token)
            ->getJson("/api/public/apps/{$app->id}/me/orders")
            ->assertOk()
            ->assertJsonPath('data.orders.0.status', 'confirmed')
            ->assertJsonPath('data.orders.1.status', 'new');
    }

    #[Test]
    public function nobody_sees_an_order_that_is_not_theirs(): void
    {
        $app = $this->app();
        $sam = $this->signUp($app);

        $this->withToken($sam)->postJson("/api/public/apps/{$app->id}/orders", $this->order())->assertCreated();

        // A guest's order, and another customer of the same shop.
        $this->flushHeaders();
        $this->postJson("/api/public/apps/{$app->id}/orders", $this->order())->assertCreated();
        $dani = $this->signUp($app, ['email' => 'dani@example.com', 'name' => 'Dani']);

        $this->withToken($dani)
            ->getJson("/api/public/apps/{$app->id}/me/orders")
            ->assertOk()
            ->assertJsonCount(0, 'data.orders');

        $this->withToken($sam)
            ->getJson("/api/public/apps/{$app->id}/me/orders")
            ->assertOk()
            ->assertJsonCount(1, 'data.orders');

        $this->flushHeaders();
        $this->getJson("/api/public/apps/{$app->id}/me/orders")->assertUnauthorized();

        $other = $this->app();
        $this->withToken($sam)->getJson("/api/public/apps/{$other->id}/me/orders")->assertUnauthorized();
    }

    #[Test]
    public function deleting_the_account_removes_the_person_and_keeps_the_shops_order(): void
    {
        $owner = User::factory()->create();
        $app = App::factory()->create(['organization_id' => $owner->organization_id]);
        $token = $this->signUp($app);

        $this->withToken($token)->postJson("/api/public/apps/{$app->id}/orders", $this->order())->assertCreated();
        $this->withToken($token)->deleteJson("/api/public/apps/{$app->id}/me")->assertNoContent();

        $this->assertSame(0, DB::table('app_users')->count());
        $this->assertSame(0, DB::table('app_user_tokens')->count());
        $this->withToken($token)->getJson("/api/public/apps/{$app->id}/me")->assertUnauthorized();

        $this->flushHeaders();

        $orders = $this->actingAs($owner)
            ->getJson("/api/apps/{$app->id}/orders")
            ->assertOk()
            ->json('data.orders');

        $this->assertCount(1, $orders);
        $this->assertNull($orders[0]['customer']);

        $this->forgetAuthentication();
        $this->signUp($app);
    }

    #[Test]
    public function nobody_deletes_an_account_that_is_not_theirs(): void
    {
        $app = $this->app();
        $this->signUp($app);
        $this->flushHeaders();

        $this->deleteJson("/api/public/apps/{$app->id}/me")->assertUnauthorized();

        $stranger = $this->signUp($this->app());
        $this->withToken($stranger)->deleteJson("/api/public/apps/{$app->id}/me")->assertUnauthorized();

        $this->assertSame(2, DB::table('app_users')->count());
    }

    #[Test]
    public function an_account_cannot_be_made_in_an_app_that_does_not_exist(): void
    {
        $this->postJson('/api/public/apps/app_missing/users', self::SAM)->assertNotFound();
    }

    #[Test]
    public function guessing_one_persons_password_is_slowed_whatever_else_is_going_on(): void
    {
        $app = App::factory()->create();

        $guess = fn (string $email) => $this->postJson("/api/public/apps/{$app->id}/sessions", [
            'email' => $email,
            'password' => 'not-the-password',
        ]);

        // Room for an honest minute of being locked out: ten tries.
        foreach (range(1, 10) as $try) {
            $guess('ali@example.com')->assertStatus(422);
        }

        $guess('ali@example.com')->assertStatus(429);

        // Someone else signing in to the same shop is not caught up in it.
        $guess('sara@example.com')->assertStatus(422);
    }
}
