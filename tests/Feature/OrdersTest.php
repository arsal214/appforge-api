<?php

namespace Tests\Feature;

use App\Models\App;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OrdersTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function order(array $overrides = []): array
    {
        return array_replace([
            'lines' => [
                ['itemId' => 'i-1', 'title' => 'Margherita', 'price' => '12.00', 'quantity' => 2],
                ['itemId' => 'i-2', 'title' => 'Garlic bread', 'price' => null, 'quantity' => 1],
            ],
            'total' => '28.50',
            'details' => [
                ['label' => 'Delivery address', 'value' => '12 Mill Lane', 'kind' => 'paragraph'],
                ['label' => 'Phone', 'value' => '+44 7700 900123', 'kind' => 'phone'],
            ],
            'platform' => 'android',
        ], $overrides);
    }

    #[Test]
    public function an_app_takes_an_order_from_the_phone_and_numbers_it(): void
    {
        $user = User::factory()->create();
        $app = App::factory()->create(['organization_id' => $user->organization_id]);

        $this->postJson("/api/public/apps/{$app->id}/orders", $this->order())
            ->assertCreated()
            ->assertJsonPath('data.number', 1);

        $this->postJson("/api/public/apps/{$app->id}/orders", $this->order())
            ->assertCreated()
            ->assertJsonPath('data.number', 2);

        // Numbering is per app, so a second app starts at one again.
        $other = App::factory()->create(['organization_id' => $user->organization_id]);
        $this->postJson("/api/public/apps/{$other->id}/orders", $this->order())
            ->assertCreated()
            ->assertJsonPath('data.number', 1);
    }

    #[Test]
    public function the_order_payload_matches_the_panels_zod_schema(): void
    {
        $user = User::factory()->create();
        $app = App::factory()->create(['organization_id' => $user->organization_id]);

        $this->postJson("/api/public/apps/{$app->id}/orders", $this->order())->assertCreated();

        $data = $this->actingAs($user)
            ->getJson("/api/apps/{$app->id}/orders")
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $data);
        $this->assertEqualsCanonicalizing([
            'id', 'appId', 'number', 'status', 'test', 'lines', 'details',
            'total', 'platform', 'createdAt', 'updatedAt',
        ], array_keys($data[0]));

        $this->assertSame('new', $data[0]['status']);
        $this->assertSame('28.50', $data[0]['total']);
        $this->assertSame('Margherita', $data[0]['lines'][0]['title']);
        $this->assertSame(2, $data[0]['lines'][0]['quantity']);
        $this->assertSame('phone', $data[0]['details'][1]['kind']);

        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/',
            $data[0]['createdAt'],
        );
    }

    #[Test]
    public function an_order_for_an_app_that_is_not_there_is_refused(): void
    {
        $user = User::factory()->create();
        $app = App::factory()->create(['organization_id' => $user->organization_id]);

        $this->postJson('/api/public/apps/app_01jmadeup/orders', $this->order())
            ->assertNotFound();

        $app->delete();

        $this->postJson("/api/public/apps/{$app->id}/orders", $this->order())
            ->assertNotFound();
    }

    #[Test]
    public function a_junk_order_is_refused(): void
    {
        $user = User::factory()->create();
        $app = App::factory()->create(['organization_id' => $user->organization_id]);
        $url = "/api/public/apps/{$app->id}/orders";

        $this->postJson($url, $this->order(['lines' => [], 'details' => []]))
            ->assertStatus(422)->assertJsonValidationErrors(['lines']);

        $this->postJson($url, $this->order([
            'lines' => [['itemId' => 'i-1', 'title' => 'Pizza', 'quantity' => 0]],
        ]))->assertStatus(422)->assertJsonValidationErrors(['lines.0.quantity']);

        $this->postJson($url, $this->order([
            'lines' => [['itemId' => 'i-1', 'title' => '', 'quantity' => 1]],
        ]))->assertStatus(422)->assertJsonValidationErrors(['lines.0.title']);

        $this->postJson($url, $this->order([
            'details' => [['label' => 'Note', 'value' => str_repeat('x', 201)]],
        ]))->assertStatus(422)->assertJsonValidationErrors(['details.0.value']);

        $this->postJson($url, $this->order([
            'details' => [['label' => 'Note', 'value' => 'ok', 'kind' => 'signature']],
        ]))->assertStatus(422)->assertJsonValidationErrors(['details.0.kind']);

        // One per item on the biggest menu the contract allows, and no more.
        $this->postJson($url, $this->order([
            'lines' => array_fill(0, 61, ['itemId' => 'i', 'title' => 'x', 'quantity' => 1]),
        ]))->assertStatus(422)->assertJsonValidationErrors(['lines']);

        $this->assertSame(0, $app->orders()->count());
    }

    #[Test]
    public function an_order_with_no_details_is_still_accepted(): void
    {
        $user = User::factory()->create();
        $app = App::factory()->create(['organization_id' => $user->organization_id]);

        $this->postJson("/api/public/apps/{$app->id}/orders", $this->order([
            'details' => [],
            'total' => null,
            'platform' => null,
        ]))->assertCreated();

        $data = $this->actingAs($user)
            ->getJson("/api/apps/{$app->id}/orders")
            ->assertOk()
            ->json('data.0');

        $this->assertSame([], $data['details']);
        $this->assertNull($data['total']);
        $this->assertNull($data['platform']);
    }

    #[Test]
    public function an_appointment_business_sends_a_request_with_no_basket(): void
    {
        $user = User::factory()->create();
        $app = App::factory()->create(['organization_id' => $user->organization_id]);

        $this->postJson("/api/public/apps/{$app->id}/orders", [
            'lines' => [],
            'total' => null,
            'details' => [
                ['label' => 'Service', 'value' => 'Haircut', 'kind' => 'line'],
                ['label' => 'When suits you', 'value' => 'Tuesday afternoon', 'kind' => 'line'],
                ['label' => 'Phone', 'value' => '+44 7700 900123', 'kind' => 'phone'],
            ],
            'platform' => 'ios',
        ])
            ->assertCreated()
            ->assertJsonPath('data.number', 1);

        $data = $this->actingAs($user)
            ->getJson("/api/apps/{$app->id}/orders")
            ->assertOk()
            ->json('data.0');

        $this->assertSame([], $data['lines']);
        $this->assertNull($data['total']);
        $this->assertCount(3, $data['details']);
        $this->assertSame('new', $data['status']);

        $this->postJson("/api/public/apps/{$app->id}/orders", $this->order())
            ->assertCreated()
            ->assertJsonPath('data.number', 2);
    }

    #[Test]
    public function the_owner_can_send_themselves_a_test_order_from_the_preview(): void
    {
        $user = User::factory()->create();
        $app = App::factory()->create(['organization_id' => $user->organization_id]);

        $data = $this->actingAs($user)
            ->postJson("/api/apps/{$app->id}/orders/test", $this->order())
            ->assertCreated()
            ->json('data');

        $this->assertTrue($data['test'], 'a test order has to say so');
        $this->assertSame(1, $data['number']);
        $this->assertSame('Margherita', $data['lines'][0]['title']);

        $listed = $this->actingAs($user)
            ->getJson("/api/apps/{$app->id}/orders")
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $listed);
        $this->assertTrue($listed[0]['test']);
    }

    #[Test]
    public function a_real_order_is_never_marked_as_a_test(): void
    {
        $user = User::factory()->create();
        $app = App::factory()->create(['organization_id' => $user->organization_id]);

        $this->postJson("/api/public/apps/{$app->id}/orders", $this->order())
            ->assertCreated();

        $this->assertFalse(
            $this->actingAs($user)->getJson("/api/apps/{$app->id}/orders")->json('data.0.test'),
            'an order somebody is waiting on must not be dismissable as a test',
        );
    }

    #[Test]
    public function clearing_tests_leaves_the_real_orders_alone(): void
    {
        $user = User::factory()->create();
        $app = App::factory()->create(['organization_id' => $user->organization_id]);

        $this->postJson("/api/public/apps/{$app->id}/orders", $this->order())->assertCreated();
        $this->actingAs($user)
            ->postJson("/api/apps/{$app->id}/orders/test", $this->order())
            ->assertCreated();
        $this->postJson("/api/public/apps/{$app->id}/orders", $this->order())->assertCreated();

        $this->actingAs($user)->deleteJson("/api/apps/{$app->id}/orders/test")->assertOk();

        $left = $this->actingAs($user)
            ->getJson("/api/apps/{$app->id}/orders")
            ->assertOk()
            ->json('data');

        $this->assertSame([3, 1], array_column($left, 'number'));
        $this->assertSame([false, false], array_column($left, 'test'));
    }

    #[Test]
    public function only_someone_who_can_build_may_send_or_clear_a_test(): void
    {
        $owner = User::factory()->create();
        $staff = User::factory()->create([
            'organization_id' => $owner->organization_id,
            'role' => 'staff',
        ]);
        $app = App::factory()->create(['organization_id' => $owner->organization_id]);

        $this->actingAs($staff)
            ->postJson("/api/apps/{$app->id}/orders/test", $this->order())
            ->assertForbidden();

        $this->actingAs($staff)
            ->deleteJson("/api/apps/{$app->id}/orders/test")
            ->assertForbidden();
    }

    #[Test]
    public function another_organisation_cannot_send_a_test_into_your_app(): void
    {
        $user = User::factory()->create();
        $other = Organization::factory()->create();
        $theirs = App::factory()->for($other)->create();

        $this->actingAs($user)
            ->postJson("/api/apps/{$theirs->id}/orders/test", $this->order())
            ->assertNotFound();

        $this->assertSame(0, $theirs->orders()->count());
    }

    #[Test]
    public function a_test_order_is_refused_without_a_login(): void
    {
        $app = App::factory()->create();

        $this->postJson("/api/apps/{$app->id}/orders/test", $this->order())
            ->assertUnauthorized();
    }

    #[Test]
    public function orders_come_back_newest_first_and_filter_by_status(): void
    {
        $user = User::factory()->create();
        $app = App::factory()->create(['organization_id' => $user->organization_id]);

        $this->postJson("/api/public/apps/{$app->id}/orders", $this->order())->assertCreated();
        $this->postJson("/api/public/apps/{$app->id}/orders", $this->order())->assertCreated();

        $numbers = array_column(
            $this->actingAs($user)->getJson("/api/apps/{$app->id}/orders")->json('data'),
            'number',
        );
        $this->assertSame([2, 1], $numbers);

        $first = $app->orders()->where('number', 1)->firstOrFail();

        $this->actingAs($user)
            ->patchJson("/api/apps/{$app->id}/orders/{$first->id}", ['status' => 'completed'])
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');

        $this->assertSame(
            [1],
            array_column(
                $this->actingAs($user)
                    ->getJson("/api/apps/{$app->id}/orders?status=completed")
                    ->json('data'),
                'number',
            ),
        );

        $this->assertSame(
            [2],
            array_column(
                $this->actingAs($user)->getJson("/api/apps/{$app->id}/orders?status=new")->json('data'),
                'number',
            ),
        );
    }

    #[Test]
    public function a_status_outside_the_list_is_refused(): void
    {
        $user = User::factory()->create();
        $app = App::factory()->create(['organization_id' => $user->organization_id]);

        $this->postJson("/api/public/apps/{$app->id}/orders", $this->order())->assertCreated();
        $order = $app->orders()->firstOrFail();

        $this->actingAs($user)
            ->patchJson("/api/apps/{$app->id}/orders/{$order->id}", ['status' => 'refunded'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);

        $this->actingAs($user)
            ->getJson("/api/apps/{$app->id}/orders?status=refunded")
            ->assertStatus(422);

        $this->assertSame('new', $order->fresh()->status);
    }

    #[Test]
    public function another_organisations_orders_are_invisible(): void
    {
        $user = User::factory()->create();
        $other = Organization::factory()->create();
        $theirs = App::factory()->for($other)->create();

        $this->postJson("/api/public/apps/{$theirs->id}/orders", $this->order())->assertCreated();
        $order = $theirs->orders()->firstOrFail();

        $this->actingAs($user)->getJson("/api/apps/{$theirs->id}/orders")->assertNotFound();
        $this->actingAs($user)
            ->patchJson("/api/apps/{$theirs->id}/orders/{$order->id}", ['status' => 'cancelled'])
            ->assertNotFound();

        $this->assertSame('new', $order->fresh()->status);
    }

    #[Test]
    public function an_order_belonging_to_another_app_is_not_reachable_through_this_one(): void
    {
        $user = User::factory()->create();
        $app = App::factory()->create(['organization_id' => $user->organization_id]);
        $sibling = App::factory()->create(['organization_id' => $user->organization_id]);

        $this->postJson("/api/public/apps/{$sibling->id}/orders", $this->order())->assertCreated();
        $order = $sibling->orders()->firstOrFail();

        $this->actingAs($user)
            ->patchJson("/api/apps/{$app->id}/orders/{$order->id}", ['status' => 'completed'])
            ->assertNotFound();
    }

    #[Test]
    public function the_orders_list_needs_a_login(): void
    {
        $app = App::factory()->create();

        $this->getJson("/api/apps/{$app->id}/orders")->assertUnauthorized();
    }

    #[Test]
    public function deleting_an_app_for_good_takes_its_orders_with_it(): void
    {
        $user = User::factory()->create();
        $app = App::factory()->create(['organization_id' => $user->organization_id]);

        $this->postJson("/api/public/apps/{$app->id}/orders", $this->order())->assertCreated();

        // The bin keeps them: a soft delete is reversible and so are the orders.
        $this->actingAs($user)->deleteJson("/api/apps/{$app->id}")->assertOk();
        $this->assertDatabaseCount('orders', 1);

        $app->forceDelete();
        $this->assertDatabaseCount('orders', 0);
    }
}
