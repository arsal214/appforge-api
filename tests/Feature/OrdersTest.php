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
            'id', 'appId', 'number', 'status', 'lines', 'details',
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

        $this->postJson($url, $this->order(['lines' => []]))
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
