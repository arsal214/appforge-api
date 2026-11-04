<?php

namespace Tests\Feature;

use App\Mail\OrderArrived;
use App\Models\App as ClientApp;
use App\Models\Order;
use App\Models\User;
use App\Providers\AppServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OrderNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function order(array $overrides = []): array
    {
        return array_merge([
            'lines' => [[
                'itemId' => 'itm_marg',
                'title' => 'Margherita',
                'price' => '9.50',
                'quantity' => 2,
            ]],
            'details' => [
                ['label' => 'Name', 'value' => 'Ayesha'],
                ['label' => 'Phone', 'value' => '+92 300 1234567', 'kind' => 'phone'],
            ],
            'total' => '19.00',
            'platform' => 'ios',
        ], $overrides);
    }

    #[Test]
    public function the_owner_is_emailed_when_a_customer_places_an_order(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $app = ClientApp::factory()->create(['organization_id' => $owner->organization_id]);

        $this->postJson("/api/public/apps/{$app->id}/orders", $this->order())
            ->assertCreated();

        Mail::assertQueued(
            OrderArrived::class,
            fn (OrderArrived $mail) => $mail->hasTo($owner->email)
                && $mail->appId === $app->id
                && $mail->order->number === 1,
        );
    }

    #[Test]
    public function the_email_is_queued_rather_than_sent_inside_the_request(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $app = ClientApp::factory()->create(['organization_id' => $owner->organization_id]);

        $this->postJson("/api/public/apps/{$app->id}/orders", $this->order())
            ->assertCreated();

        Mail::assertQueued(OrderArrived::class);
        Mail::assertNothingSent();
    }

    #[Test]
    public function only_an_owner_hears_about_it(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $editor = User::factory()->create([
            'organization_id' => $owner->organization_id,
            'role' => 'editor',
        ]);
        $staff = User::factory()->create([
            'organization_id' => $owner->organization_id,
            'role' => 'staff',
        ]);
        $app = ClientApp::factory()->create(['organization_id' => $owner->organization_id]);

        $this->postJson("/api/public/apps/{$app->id}/orders", $this->order())
            ->assertCreated();

        Mail::assertQueued(
            OrderArrived::class,
            fn (OrderArrived $mail) => $mail->hasTo($owner->email),
        );

        foreach ([$editor, $staff] as $other) {
            Mail::assertNotQueued(
                OrderArrived::class,
                fn (OrderArrived $mail) => $mail->hasTo($other->email),
            );
        }
    }

    #[Test]
    public function another_organisations_owner_is_never_told(): void
    {
        $mine = User::factory()->create(['role' => 'owner']);
        $theirs = User::factory()->create(['role' => 'owner']);
        $app = ClientApp::factory()->create(['organization_id' => $mine->organization_id]);

        $this->postJson("/api/public/apps/{$app->id}/orders", $this->order())
            ->assertCreated();

        Mail::assertNotQueued(
            OrderArrived::class,
            fn (OrderArrived $mail) => $mail->hasTo($theirs->email),
        );
    }

    #[Test]
    public function a_booking_request_with_no_basket_is_announced_too(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $app = ClientApp::factory()->create(['organization_id' => $owner->organization_id]);

        $this->postJson("/api/public/apps/{$app->id}/orders", $this->order([
            'lines' => [],
            'total' => null,
        ]))->assertCreated();

        Mail::assertQueued(
            OrderArrived::class,
            fn (OrderArrived $mail) => $mail->hasTo($owner->email)
                && $mail->order->lines === [],
        );
    }

    #[Test]
    public function a_booking_is_called_a_request_even_once_it_names_a_service(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $app = ClientApp::factory()->create([
            'organization_id' => $owner->organization_id,
            'name' => 'Snip',
        ]);

        $this->postJson("/api/public/apps/{$app->id}/orders", [
            'kind' => 'request',
            'lines' => [
                ['itemId' => 'svc-colour', 'title' => 'Colour', 'price' => null, 'quantity' => 1],
            ],
            'total' => null,
            'details' => [['label' => 'Name', 'value' => 'Ayesha']],
        ])->assertCreated();

        Mail::assertQueued(OrderArrived::class, function (OrderArrived $mail): bool {
            $subject = $mail->envelope()->subject;

            return $subject === 'New request #1 — Snip';
        });
    }

    #[Test]
    public function a_basket_is_still_called_an_order(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $app = ClientApp::factory()->create([
            'organization_id' => $owner->organization_id,
            'name' => 'Snip',
        ]);

        $this->postJson("/api/public/apps/{$app->id}/orders", $this->order())
            ->assertCreated();

        Mail::assertQueued(
            OrderArrived::class,
            fn (OrderArrived $mail): bool => $mail->envelope()->subject === 'New order #1 — Snip',
        );
    }

    #[Test]
    public function an_owners_own_test_order_emails_so_the_path_can_be_checked(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $app = ClientApp::factory()->create(['organization_id' => $owner->organization_id]);

        $this->actingAs($owner)
            ->postJson("/api/apps/{$app->id}/orders/test", $this->order())
            ->assertCreated();

        Mail::assertQueued(
            OrderArrived::class,
            fn (OrderArrived $mail) => $mail->order->is_test === true,
        );
    }

    #[Test]
    public function the_order_is_still_taken_when_nobody_can_be_emailed(): void
    {
        $editor = User::factory()->create(['role' => 'editor']);
        $app = ClientApp::factory()->create(['organization_id' => $editor->organization_id]);

        $this->postJson("/api/public/apps/{$app->id}/orders", $this->order())
            ->assertCreated()
            ->assertJsonPath('data.number', 1);

        $this->assertSame(1, Order::where('app_id', $app->id)->count());
        Mail::assertNothingQueued();
    }

    #[Test]
    public function the_email_renders_without_the_app_model_being_fetched_again(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $app = ClientApp::factory()->create([
            'organization_id' => $owner->organization_id,
            'name' => 'Sams Pizza',
        ]);

        $this->postJson("/api/public/apps/{$app->id}/orders", $this->order())
            ->assertCreated();

        $order = Order::where('app_id', $app->id)->firstOrFail();

        $this->assertNull(
            ClientApp::find($app->id),
            'the organisation scope stopped failing closed, so this test no '
                .'longer proves anything',
        );

        $body = (new OrderArrived($app->id, $app->name, $order))->render();

        $this->assertStringContainsString('Sams Pizza', $body);
        $this->assertStringContainsString('Margherita', $body);
        $this->assertStringContainsString('Ayesha', $body);
        $this->assertStringContainsString('+92 300 1234567', $body);
    }

    #[Test]
    public function the_app_payload_carries_how_many_orders_are_waiting(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $app = ClientApp::factory()->create(['organization_id' => $owner->organization_id]);

        foreach ([1, 2, 3] as $number) {
            Order::create([
                'app_id' => $app->id,
                'number' => $number,
                'status' => $number === 3 ? 'done' : 'new',
                'is_test' => false,
                'lines' => [],
                'details' => [['label' => 'Name', 'value' => 'Ayesha']],
            ]);
        }

        $this->actingAs($owner)
            ->getJson('/api/apps')
            ->assertOk()
            ->assertJsonPath('data.0.newOrders', 2);
    }

    #[Test]
    public function an_owners_own_test_order_is_not_counted_as_waiting(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $app = ClientApp::factory()->create(['organization_id' => $owner->organization_id]);

        Order::create([
            'app_id' => $app->id,
            'number' => 1,
            'status' => 'new',
            'is_test' => true,
            'lines' => [],
            'details' => [['label' => 'Name', 'value' => 'Ayesha']],
        ]);

        $this->actingAs($owner)
            ->getJson('/api/apps')
            ->assertOk()
            ->assertJsonPath('data.0.newOrders', 0)
            ->assertJsonPath('data.0.newTests', 1);
    }

    #[Test]
    public function tests_and_real_orders_are_counted_apart(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $app = ClientApp::factory()->create(['organization_id' => $owner->organization_id]);

        $make = function (int $number, bool $test, string $status) use ($app): void {
            Order::create([
                'app_id' => $app->id,
                'number' => $number,
                'status' => $status,
                'is_test' => $test,
                'lines' => [],
                'details' => [['label' => 'Name', 'value' => 'Ayesha']],
            ]);
        };

        $make(1, false, 'new');
        $make(2, true, 'new');
        $make(3, true, 'new');
        $make(4, true, 'confirmed');

        $this->actingAs($owner)
            ->getJson('/api/apps')
            ->assertOk()
            ->assertJsonPath('data.0.newOrders', 1)
            ->assertJsonPath('data.0.newTests', 2);
    }

    #[Test]
    public function the_count_is_present_rather_than_quietly_missing(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        ClientApp::factory()->create(['organization_id' => $owner->organization_id]);

        $this->actingAs($owner)
            ->getJson('/api/apps')
            ->assertOk()
            ->assertJsonStructure(['data' => [['newOrders', 'newTests']]]);
    }

    #[Test]
    public function production_refuses_to_start_with_a_mailer_that_delivers_nothing(): void
    {
        foreach (['log', 'array'] as $mailer) {
            $provider = new AppServiceProvider($this->app);

            $this->app->detectEnvironment(fn () => 'production');
            config(['mail.default' => $mailer]);

            try {
                $provider->boot();
                $this->fail("[{$mailer}] was accepted in production");
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString($mailer, $e->getMessage());
                $this->assertStringContainsString('never told', $e->getMessage());
            }
        }
    }

    #[Test]
    public function a_local_machine_is_left_alone_with_the_log_mailer(): void
    {
        $provider = new AppServiceProvider($this->app);

        $this->app->detectEnvironment(fn () => 'local');
        config(['mail.default' => 'log']);

        $provider->boot();

        $this->assertSame('log', config('mail.default'));
    }

    #[Test]
    public function a_real_mailer_in_production_starts_normally(): void
    {
        $provider = new AppServiceProvider($this->app);

        $this->app->detectEnvironment(fn () => 'production');
        config(['mail.default' => 'smtp']);

        $provider->boot();

        $this->assertSame('smtp', config('mail.default'));
    }
}
