<?php

namespace Tests\Feature;

use App\Mail\OrderStatusChanged;
use App\Models\App as ClientApp;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OrderStatusEmailTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private ClientApp $shop;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->owner = User::factory()->create(['role' => 'owner']);
        $this->shop = ClientApp::factory()->create([
            'organization_id' => $this->owner->organization_id,
            'name' => 'Sams Pizza',
        ]);
    }

    /** Sent by the phone, signed in as Ali unless `$guest`. */
    private function placed(bool $guest = false, string $kind = 'order'): Order
    {
        $headers = [];

        if (! $guest) {
            $token = $this->postJson("/api/public/apps/{$this->shop->id}/users", [
                'name' => 'Ali',
                'email' => 'ali@example.com',
                'password' => 'a-long-enough-password',
            ])->assertCreated()->json('data.token');

            $headers['Authorization'] = "Bearer {$token}";
        }

        $this->withHeaders($headers)->postJson("/api/public/apps/{$this->shop->id}/orders", [
            'kind' => $kind,
            'lines' => [['itemId' => 'i-1', 'title' => 'Margherita', 'price' => '£9.50', 'quantity' => 2]],
            'total' => '£19.00',
            'details' => [['label' => 'Phone', 'value' => '+44 7700 900123', 'kind' => 'phone']],
        ])->assertCreated();

        $this->withHeaders(['Authorization' => '']);

        return Order::where('app_id', $this->shop->id)->latest('number')->firstOrFail();
    }

    private function move(Order $order, string $status): void
    {
        $this->actingAs($this->owner)
            ->patchJson("/api/apps/{$this->shop->id}/orders/{$order->id}", ['status' => $status])
            ->assertOk();
    }

    #[Test]
    public function a_signed_in_customer_is_emailed_when_their_order_is_confirmed(): void
    {
        $order = $this->placed();

        $this->move($order, 'confirmed');

        Mail::assertQueued(
            OrderStatusChanged::class,
            fn (OrderStatusChanged $mail) => $mail->hasTo('ali@example.com')
                && $mail->status === 'confirmed'
                && $mail->hasFrom(config('mail.from.address'), 'Sams Pizza')
                && $mail->envelope()->subject === 'Your order #1 is confirmed — Sams Pizza',
        );
    }

    #[Test]
    public function complete_and_cancelled_are_told_too_each_once(): void
    {
        $order = $this->placed();

        $this->move($order, 'completed');
        $this->move($order, 'completed');
        $this->move($order, 'cancelled');

        Mail::assertQueued(OrderStatusChanged::class, 2);
        Mail::assertQueued(OrderStatusChanged::class, fn ($mail) => $mail->status === 'completed');
        Mail::assertQueued(OrderStatusChanged::class, fn ($mail) => $mail->status === 'cancelled');
    }

    #[Test]
    public function a_guest_has_no_address_and_hears_nothing(): void
    {
        $order = $this->placed(guest: true);

        $this->move($order, 'confirmed');

        Mail::assertNotQueued(OrderStatusChanged::class);
    }

    #[Test]
    public function going_back_to_new_and_a_finished_booking_say_nothing(): void
    {
        $order = $this->placed();
        $this->move($order, 'confirmed');
        $this->move($order, 'new');

        $booking = $this->placed(guest: true, kind: 'booking');
        $booking->update(['app_user_id' => $order->app_user_id]);
        $this->move($booking, 'completed');

        Mail::assertQueued(OrderStatusChanged::class, 1);
    }

    #[Test]
    public function a_booking_is_told_it_is_confirmed(): void
    {
        $booking = $this->placed(kind: 'booking');

        $this->move($booking, 'confirmed');

        Mail::assertQueued(
            OrderStatusChanged::class,
            fn ($mail) => $mail->envelope()->subject === 'Your booking #1 is confirmed — Sams Pizza',
        );
    }

    #[Test]
    public function a_deleted_account_is_not_written_to(): void
    {
        $order = $this->placed();
        $order->appUser->delete();

        $this->move($order->fresh(), 'confirmed');

        Mail::assertNotQueued(OrderStatusChanged::class);
    }

    #[Test]
    public function the_email_lists_the_order_as_text(): void
    {
        $order = $this->placed();
        $order->update(['lines' => [
            ['itemId' => 'i-1', 'title' => '[Free pizza](https://evil.example)', 'price' => null, 'quantity' => 1],
        ]]);

        $body = (new OrderStatusChanged('Sams Pizza', $order->fresh(), 'cancelled'))->render();

        $this->assertStringContainsString('Your order #1 was cancelled', $body);
        $this->assertStringContainsString('Sams Pizza has cancelled it', $body);
        $this->assertStringContainsString('Free pizza', $body);
        $this->assertStringNotContainsString('href="https://evil.example"', $body);
    }
}
