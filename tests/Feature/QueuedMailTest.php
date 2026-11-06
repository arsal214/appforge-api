<?php

namespace Tests\Feature;

use App\Models\App;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class QueuedMailTest extends TestCase
{
    use RefreshDatabase;

    private function queuedOrder(): array
    {
        $user = User::factory()->create();
        $app = App::factory()->create([
            'organization_id' => $user->organization_id,
            'name' => 'Mill Lane',
        ]);

        $this->postJson("/api/public/apps/{$app->id}/orders", [
            'lines' => [],
            'total' => null,
            'details' => [['label' => 'Name', 'value' => 'Ayesha']],
        ])->assertCreated();

        return [$user, $app, $app->orders()->firstOrFail()];
    }

    #[Test]
    public function an_email_whose_order_was_deleted_is_dropped_rather_than_failing(): void
    {
        // The real queue, not `sync`: the whole point is what happens between
        // the job being written down and the worker picking it up.
        config(['queue.default' => 'database']);

        [, , $order] = $this->queuedOrder();

        // Placing the order queued the email to the shop; that is the job
        // under test, not one this test invented.
        $this->assertSame(1, DB::table('jobs')->count());

        // What "clear the test records" or a closed account does.
        $order->delete();

        $this->artisan('queue:work', ['--once' => true, '--stop-when-empty' => true])
            ->assertSuccessful();

        $this->assertSame(0, DB::table('jobs')->count(), 'the job is still stuck in the queue');
        $this->assertSame(
            0,
            DB::table('failed_jobs')->count(),
            'a deleted order poisoned the queue instead of being dropped',
        );
    }

    #[Test]
    public function an_email_whose_order_is_still_there_is_sent(): void
    {
        config(['queue.default' => 'database']);

        [, , $order] = $this->queuedOrder();

        $this->assertSame(1, DB::table('jobs')->count());

        $this->artisan('queue:work', ['--once' => true, '--stop-when-empty' => true])
            ->assertSuccessful();

        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->assertNotNull($order->fresh(), 'the order itself should be untouched');
    }

    #[Test]
    public function the_shop_is_emailed_once_per_owner_and_not_for_a_replay(): void
    {
        Mail::fake();

        $owner = User::factory()->create();
        $app = App::factory()->create(['organization_id' => $owner->organization_id]);

        $order = [
            'idempotencyKey' => 'one-send',
            'lines' => [],
            'total' => null,
            'details' => [['label' => 'Name', 'value' => 'Ayesha']],
        ];

        $this->postJson("/api/public/apps/{$app->id}/orders", $order)->assertCreated();
        $this->postJson("/api/public/apps/{$app->id}/orders", $order)->assertCreated();

        Mail::assertQueuedCount(1);
    }

    #[Test]
    public function every_queued_mail_in_the_app_tolerates_a_missing_record(): void
    {
        foreach (glob(app_path('Mail/*.php')) as $file) {
            $class = 'App\\Mail\\'.basename($file, '.php');

            if (! class_exists($class) || (new \ReflectionClass($class))->isAbstract()) {
                continue;
            }

            $this->assertTrue(
                (new \ReflectionClass($class))->getDefaultProperties()['deleteWhenMissingModels'] ?? false,
                "{$class} would fail its queue job if the record it names is deleted first",
            );
        }
    }

    #[Test]
    public function an_order_still_reaches_the_panel_when_the_email_cannot_be_queued(): void
    {
        $owner = User::factory()->create();
        $app = App::factory()->create(['organization_id' => $owner->organization_id]);

        // Whatever goes wrong on the way to the mail server, the record of
        // the order is the thing the shop cannot afford to lose.
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('mail is down'));

        $this->postJson("/api/public/apps/{$app->id}/orders", [
            'lines' => [],
            'total' => null,
            'details' => [['label' => 'Name', 'value' => 'Ayesha']],
        ])->assertCreated();

        $this->assertSame(1, Order::where('app_id', $app->id)->count());
    }
}
