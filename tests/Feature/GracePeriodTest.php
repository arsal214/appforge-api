<?php

namespace Tests\Feature;

use App\Models\AccountEvent;
use App\Models\App;
use App\Models\Organization;
use App\Models\Upload;
use App\Models\User;
use App\Support\Tenant\TenantData;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GracePeriodTest extends TestCase
{
    use RefreshDatabase;

    private function close(User $owner): TestResponse
    {
        return $this->actingAs($owner)->deleteJson('/api/organization', [
            'name' => $owner->organization->name,
        ]);
    }

    #[Test]
    public function closing_an_account_schedules_it_rather_than_doing_it(): void
    {
        $owner = User::factory()->create();
        $app = App::factory()->create(['organization_id' => $owner->organization_id]);

        $this->close($owner)->assertOk();

        $this->assertDatabaseHas('apps', ['id' => $app->id]);
        $this->assertDatabaseHas('users', ['id' => $owner->id]);

        $organization = $owner->organization->fresh();

        $this->assertNotNull($organization->closing_at);
        $this->assertSame($owner->id, $organization->closing_by);
        $this->assertEqualsWithDelta(
            Organization::GRACE_DAYS,
            now()->diffInDays($organization->closing_at),
            1,
        );
    }

    #[Test]
    public function the_panel_is_told_the_date_so_it_can_say_it_out_loud(): void
    {
        $owner = User::factory()->create();

        $closingAt = $this->close($owner)->assertOk()->json('data.closingAt');

        $this->assertNotNull($closingAt);

        // A fresh instance, because `actingAs` keeps one User object across
        // requests and its cached relation would answer from before the
        // close. A real second request resolves the user from its token.
        $this->assertSame(
            $closingAt,
            $this->actingAs($owner->fresh())
                ->getJson('/api/auth/me')
                ->json('data.organization.closingAt'),
            'someone who signs in again is not told their account is closing',
        );
    }

    #[Test]
    public function everything_keeps_working_during_the_wind_down(): void
    {
        $owner = User::factory()->create();
        $app = App::factory()->create(['organization_id' => $owner->organization_id]);

        $this->close($owner)->assertOk();

        $this->actingAs($owner)->getJson('/api/apps')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($owner)->getJson("/api/apps/{$app->id}")->assertOk();
        $this->actingAs($owner)->getJson('/api/account/export')->assertOk();

        // A customer of theirs can still place an order: the shop asked to
        // close, not to go dark this afternoon.
        $this->postJson("/api/public/apps/{$app->id}/orders", [
            'lines' => [],
            'total' => null,
            'details' => [['label' => 'Name', 'value' => 'Ayesha']],
        ])->assertCreated();
    }

    #[Test]
    public function the_owner_can_take_it_back(): void
    {
        $owner = User::factory()->create();

        $this->close($owner)->assertOk();

        $this->actingAs($owner)
            ->postJson('/api/organization/reopen')
            ->assertOk()
            ->assertJsonPath('data.closingAt', null);

        $organization = $owner->organization->fresh();

        $this->assertNull($organization->closing_at);
        $this->assertNull($organization->closing_by);
    }

    #[Test]
    public function an_account_taken_back_is_not_erased_when_the_date_passes(): void
    {
        $owner = User::factory()->create();
        $app = App::factory()->create(['organization_id' => $owner->organization_id]);

        $this->close($owner)->assertOk();
        $this->actingAs($owner)->postJson('/api/organization/reopen')->assertOk();

        $this->travel(Organization::GRACE_DAYS + 5)->days();
        $this->artisan('appforge:close-accounts')->assertSuccessful();
        $this->travelBack();

        $this->assertDatabaseHas('organizations', ['id' => $owner->organization_id]);
        $this->assertDatabaseHas('apps', ['id' => $app->id]);
    }

    #[Test]
    public function the_day_before_is_still_safe(): void
    {
        $owner = User::factory()->create();

        $this->close($owner)->assertOk();

        $this->travel(Organization::GRACE_DAYS - 1)->days();
        $this->artisan('appforge:close-accounts')->assertSuccessful();
        $this->travelBack();

        $this->assertDatabaseHas('organizations', ['id' => $owner->organization_id]);
    }

    #[Test]
    public function the_sweep_erases_it_once_the_time_is_up(): void
    {
        Storage::fake('public');

        $owner = User::factory()->create();
        $app = App::factory()->create(['organization_id' => $owner->organization_id]);
        $upload = Upload::factory()->inOrganization($owner->organization)->create();
        Storage::disk('public')->put($upload->path, 'an image');

        $this->close($owner)->assertOk();

        $this->travel(Organization::GRACE_DAYS + 1)->days();
        $this->artisan('appforge:close-accounts')
            ->expectsOutputToContain($owner->organization->name)
            ->assertSuccessful();
        $this->travelBack();

        $this->assertDatabaseMissing('organizations', ['id' => $owner->organization_id]);
        $this->assertDatabaseMissing('apps', ['id' => $app->id]);
        $this->assertDatabaseMissing('users', ['id' => $owner->id]);
        Storage::disk('public')->assertMissing($upload->path);
    }

    #[Test]
    public function pretending_changes_nothing(): void
    {
        $owner = User::factory()->create();

        $this->close($owner)->assertOk();

        $this->travel(Organization::GRACE_DAYS + 1)->days();
        $this->artisan('appforge:close-accounts', ['--pretend' => true])
            ->assertSuccessful();
        $this->travelBack();

        $this->assertDatabaseHas('organizations', ['id' => $owner->organization_id]);
    }

    #[Test]
    public function the_sweep_leaves_every_other_account_alone(): void
    {
        $closing = User::factory()->create();
        $staying = User::factory()->create();
        App::factory()->create(['organization_id' => $staying->organization_id]);

        $this->close($closing)->assertOk();

        $this->travel(Organization::GRACE_DAYS + 1)->days();
        $this->artisan('appforge:close-accounts')->assertSuccessful();
        $this->travelBack();

        $this->assertDatabaseMissing('organizations', ['id' => $closing->organization_id]);
        $this->assertDatabaseHas('organizations', ['id' => $staying->organization_id]);
        // Read past the organisation scope: the signed-in user belongs to the
        // account that was just erased, so a scoped read sees nothing at all.
        $this->assertSame(
            1,
            DB::table('apps')->where('organization_id', $staying->organization_id)->count(),
        );
    }

    #[Test]
    public function asking_twice_does_not_move_the_date(): void
    {
        $owner = User::factory()->create();

        $first = $this->close($owner)->assertOk()->json('data.closingAt');

        $this->travel(5)->days();
        $second = $this->close($owner)->assertOk()->json('data.closingAt');
        $this->travelBack();

        $this->assertSame($first, $second, 'a second click bought another 30 days');
    }

    #[Test]
    public function both_the_asking_and_the_taking_back_are_recorded(): void
    {
        $owner = User::factory()->create();

        $this->close($owner)->assertOk();
        $this->actingAs($owner)->postJson('/api/organization/reopen')->assertOk();

        $events = AccountEvent::orderBy('id')->get();

        $this->assertSame(
            ['account.closing', 'account.reopened'],
            $events->pluck('action')->all(),
        );

        $this->assertSame(
            "asked to close {$owner->organization->name}",
            $events[0]->sentence(),
        );
    }

    #[Test]
    public function only_the_owner_can_take_an_account_back(): void
    {
        foreach (['editor', 'staff'] as $role) {
            $user = User::factory()->create(['role' => $role]);

            $this->actingAs($user)
                ->postJson('/api/organization/reopen')
                ->assertForbidden();
        }
    }

    #[Test]
    public function taking_back_an_account_that_is_not_closing_is_harmless(): void
    {
        $owner = User::factory()->create();

        $this->actingAs($owner)
            ->postJson('/api/organization/reopen')
            ->assertOk()
            ->assertJsonPath('data.closingAt', null);

        $this->assertSame(0, AccountEvent::count(), 'it recorded a thing that did not happen');
    }

    #[Test]
    public function an_account_taken_back_while_the_sweep_runs_is_left_alone(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();

        $this->close($first)->assertOk();
        $this->close($second)->assertOk();

        $rescued = $second->organization_id;

        // The sweep reads its list once and then erases one at a time. This
        // stands in for the customer clicking "keep my account" in the seconds
        // between those two things.
        $this->app->bind(TenantData::class, fn () => new class($rescued) extends TenantData
        {
            public function __construct(private string $rescued) {}

            public function erase(Organization $organization): void
            {
                Organization::whereKey($this->rescued)
                    ->update(['closing_at' => null, 'closing_by' => null]);

                parent::erase($organization);
            }
        });

        $this->travel(Organization::GRACE_DAYS + 1)->days();
        $this->artisan('appforge:close-accounts')->assertSuccessful();
        $this->travelBack();

        $this->assertDatabaseMissing('organizations', ['id' => $first->organization_id]);
        $this->assertDatabaseHas('organizations', ['id' => $rescued]);
    }

    /**
     * MySQL on this machine answers `NOW()` five hours ahead of the app, which
     * runs on UTC, so the obvious worry is an account erased five hours early.
     *
     * It cannot happen, and the reason is worth writing down: `closing_at` is
     * a `TIMESTAMP`, which MySQL converts to the session timezone on the way
     * in and back out again. Both sides of the comparison move together, so it
     * stays consistent whichever clock does the comparing — checked by running
     * this against both `where(... now())` and a raw `NOW()`.
     *
     * What this pins is the behaviour rather than the mechanism: with the
     * deadline a second away and the database six hours ahead, nothing is
     * erased until the second has actually passed.
     */
    #[Test]
    public function the_deadline_survives_a_database_clock_that_disagrees(): void
    {
        // Forced rather than assumed: this machine's MySQL already runs five
        // hours ahead of the app, but a test that only passes because of a
        // local misconfiguration proves nothing on anyone else's machine.
        DB::statement("SET time_zone = '+06:00'");

        $skew = DB::select('SELECT NOW() as db')[0]->db;

        $this->assertNotSame(
            now()->format('Y-m-d H'),
            substr($skew, 0, 13),
            'the database clock was not actually moved, so this proves nothing',
        );

        $owner = User::factory()->create();
        $this->close($owner)->assertOk();

        Organization::whereKey($owner->organization_id)
            ->update(['closing_at' => now()->addSecond()]);

        $this->artisan('appforge:close-accounts')->assertSuccessful();

        $this->assertSame(
            1,
            DB::table('organizations')->where('id', $owner->organization_id)->count(),
            "erased before its time; the database clock reads {$skew} and the app reads ".now(),
        );

        $this->travel(2)->seconds();
        $this->artisan('appforge:close-accounts')->assertSuccessful();
        $this->travelBack();

        $this->assertDatabaseMissing('organizations', ['id' => $owner->organization_id]);
    }

    #[Test]
    public function the_date_stored_is_the_date_read_back(): void
    {
        $owner = User::factory()->create();

        $fromApi = $this->close($owner)->assertOk()->json('data.closingAt');

        $stored = Organization::whereKey($owner->organization_id)->value('closing_at');

        $this->assertSame(
            $fromApi,
            $stored->toIso8601ZuluString('millisecond'),
            'the date the panel shows is not the date the sweep will act on',
        );
    }

    #[Test]
    public function a_second_owner_can_take_back_what_the_first_one_closed(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create([
            'organization_id' => $first->organization_id,
            'role' => 'owner',
            'name' => 'Bilal',
        ]);

        $this->close($first)->assertOk();

        $this->actingAs($second)
            ->postJson('/api/organization/reopen')
            ->assertOk()
            ->assertJsonPath('data.closingAt', null);

        $this->travel(Organization::GRACE_DAYS + 1)->days();
        $this->artisan('appforge:close-accounts')->assertSuccessful();
        $this->travelBack();

        $this->assertDatabaseHas('organizations', ['id' => $first->organization_id]);

        $this->assertSame(
            'Bilal',
            AccountEvent::where('action', 'account.reopened')->value('actor_name'),
        );
    }

    /**
     * `schedule:run` launches each command in its own process, which cannot
     * see a test's uncommitted rows — so running it here would prove nothing.
     * What is worth pinning is the wiring: that a cron calling it would find
     * this command, under this name, at this hour.
     */
    #[Test]
    public function the_scheduler_is_wired_to_run_it(): void
    {
        $events = app(Schedule::class)->events();

        $ours = collect($events)->first(
            fn ($event) => str_contains($event->command ?? '', 'appforge:close-accounts'),
        );

        $this->assertNotNull($ours, 'no cron would ever erase a closed account');
        $this->assertSame('0 3 * * *', $ours->expression);
    }

    /**
     * The whole of this product's background work hangs off one cron entry
     * calling `schedule:run`. Nothing in the app fails loudly when a schedule
     * entry goes missing — emails simply stop arriving and accounts stop
     * closing — so the entries are pinned here instead.
     */
    #[Test]
    public function one_cron_is_enough_to_run_everything_in_the_background(): void
    {
        $scheduled = collect(app(Schedule::class)->events())
            ->mapWithKeys(fn ($event) => [
                (string) $event->command => $event->expression,
            ]);

        $this->assertSame(
            '* * * * *',
            $scheduled->first(fn ($cron, $command) => str_contains($command, 'queue:work')),
            'nothing drains the queue, so no email this product sends would arrive',
        );

        $this->assertSame(
            '0 3 * * *',
            $scheduled->first(fn ($cron, $command) => str_contains($command, 'appforge:close-accounts')),
        );

        $drain = collect(app(Schedule::class)->events())
            ->first(fn ($event) => str_contains($event->command ?? '', 'queue:work'));

        $this->assertStringContainsString(
            '--stop-when-empty',
            $drain->command,
            'a worker started every minute that never exits would pile up one process per minute',
        );

        $this->assertNotNull(
            $drain->mutexName(),
            'without withoutOverlapping, a slow minute starts a second worker on top of the first',
        );
    }
}
