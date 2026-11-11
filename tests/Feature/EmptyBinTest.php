<?php

namespace Tests\Feature;

use App\Models\App;
use App\Models\Order;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EmptyBinTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        return $user;
    }

    private function binnedApp(User $user, int $daysAgo): App
    {
        $app = App::factory()->create(['organization_id' => $user->organization_id]);
        $app->delete();
        $app->forceFill(['deleted_at' => now()->subDays($daysAgo)])->saveQuietly();

        return $app;
    }

    #[Test]
    public function an_app_past_its_time_in_the_bin_is_deleted_for_good(): void
    {
        $user = $this->owner();
        $old = $this->binnedApp($user, App::BIN_DAYS + 1);

        $this->artisan('appforge:empty-bin')->assertSuccessful();

        $this->assertNull(App::withTrashed()->find($old->getKey()));
    }

    #[Test]
    public function an_app_still_inside_its_month_is_left_alone(): void
    {
        $user = $this->owner();
        $recent = $this->binnedApp($user, App::BIN_DAYS - 1);

        $this->artisan('appforge:empty-bin')->assertSuccessful();

        $this->assertNotNull(
            App::withTrashed()->find($recent->getKey()),
            'an app was deleted before the 30 days the panel promises',
        );
    }

    #[Test]
    public function a_live_app_is_never_touched(): void
    {
        $user = $this->owner();
        $live = App::factory()->create(['organization_id' => $user->organization_id]);

        $this->artisan('appforge:empty-bin')->assertSuccessful();

        $this->assertNotNull(App::find($live->getKey()));
    }

    #[Test]
    public function pretending_deletes_nothing(): void
    {
        $user = $this->owner();
        $old = $this->binnedApp($user, App::BIN_DAYS + 5);

        $this->artisan('appforge:empty-bin --pretend')->assertSuccessful();

        $this->assertNotNull(App::withTrashed()->find($old->getKey()));
    }

    #[Test]
    public function everything_under_the_app_goes_with_it(): void
    {
        $user = $this->owner();
        $old = $this->binnedApp($user, App::BIN_DAYS + 1);

        $order = Order::create([
            'app_id' => $old->getKey(),
            'number' => 1,
            'status' => 'new',
            'kind' => 'order',
            'is_test' => false,
            'lines' => [],
            'details' => [['label' => 'Phone', 'value' => '0300 1234567']],
        ]);

        $this->artisan('appforge:empty-bin')->assertSuccessful();

        $this->assertNull(
            Order::find($order->getKey()),
            'the app went but its orders stayed, so the customer data did not actually go',
        );
    }

    #[Test]
    public function an_app_restored_while_the_sweep_runs_survives(): void
    {
        $user = $this->owner();
        $old = $this->binnedApp($user, App::BIN_DAYS + 1);

        App::withTrashed()->find($old->getKey())->restore();

        $this->artisan('appforge:empty-bin')->assertSuccessful();

        $this->assertNotNull(
            App::find($old->getKey()),
            'an app taken out of the bin was deleted anyway',
        );
    }

    #[Test]
    public function the_sweep_is_scheduled(): void
    {
        $commands = collect(app(Schedule::class)->events())
            ->map(fn ($event) => $event->command ?? '')
            ->filter(fn (string $command) => str_contains($command, 'appforge:empty-bin'));

        $this->assertCount(1, $commands, 'appforge:empty-bin is not on the schedule.');
    }
}
