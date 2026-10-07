<?php

namespace Tests\Feature;

use App\Models\App;
use App\Models\AppConfig;
use App\Models\Organization;
use App\Models\User;
use App\Support\Analytics\EventRecorder;
use AppForge\Schema\Contract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Analytics has two halves with opposite threat models, and this file keeps
 * them apart on purpose.
 *
 * The **ingest** half is unauthenticated, so what matters is what it refuses:
 * unknown events, invented details, made-up app ids, and — the one that could
 * actually hurt — unbounded cardinality filling a disk.
 *
 * **No ingest test calls `actingAs`.** The same trap that hid a completely
 * broken PublicConfigController applies here: Laravel's RequestGuard keeps a
 * user resolved across requests in a test, so a public endpoint tested after a
 * login runs with tenancy quietly satisfied and proves nothing.
 *
 * The **read** half is authenticated, and what matters there is tenancy: one
 * organisation must not be able to see another's numbers.
 */
class AnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private function makeApp(string $name = 'Sams Pizza'): App
    {
        return App::factory()
            ->for(Organization::factory())
            ->create(['name' => $name]);
    }

    private function report(App $app, array $events, string $platform = 'ios')
    {
        return $this->postJson("/api/public/apps/{$app->getKey()}/events", [
            'platform' => $platform,
            'events' => $events,
        ]);
    }

    private function counters(App $app): Collection
    {
        return DB::table('app_event_daily')->where('app_id', $app->getKey())->get();
    }

    // ---------------------------------------------------------------- ingest

    #[Test]
    public function it_counts_reported_events(): void
    {
        $app = $this->makeApp();

        $this->report($app, [
            ['name' => 'app_open'],
            ['name' => 'screen_view', 'detail' => 'scr_home', 'count' => 3],
            ['name' => 'action', 'detail' => 'navigate'],
        ])->assertNoContent();

        $rows = $this->counters($app);

        $this->assertSame(3, $rows->count());
        $this->assertSame(1, (int) $rows->firstWhere('event', 'app_open')->count);
        $this->assertSame(3, (int) $rows->firstWhere('event', 'screen_view')->count);
    }

    #[Test]
    public function it_adds_to_an_existing_day_rather_than_duplicating(): void
    {
        $app = $this->makeApp();

        $this->report($app, [['name' => 'app_open']]);
        $this->report($app, [['name' => 'app_open', 'count' => 4]]);

        $rows = $this->counters($app);

        // One row, not two. This is the whole point of a rollup table.
        $this->assertSame(1, $rows->count());
        $this->assertSame(5, (int) $rows->first()->count);
    }

    #[Test]
    public function it_groups_repeats_inside_one_batch(): void
    {
        $app = $this->makeApp();

        $this->report($app, [
            ['name' => 'screen_view', 'detail' => 'scr_menu'],
            ['name' => 'screen_view', 'detail' => 'scr_menu', 'count' => 2],
            ['name' => 'screen_view', 'detail' => 'scr_menu'],
        ]);

        $this->assertSame(4, (int) $this->counters($app)->first()->count);
    }

    #[Test]
    public function it_keeps_platforms_apart(): void
    {
        $app = $this->makeApp();

        $this->report($app, [['name' => 'app_open']], 'ios');
        $this->report($app, [['name' => 'app_open']], 'android');

        $this->assertSame(2, $this->counters($app)->count());
    }

    #[Test]
    public function it_says_nothing_about_whether_an_app_exists(): void
    {
        $real = $this->makeApp();

        $hit = $this->report($real, [['name' => 'app_open']]);
        $miss = $this->postJson('/api/public/apps/app_0000000000000000000000000/events', [
            'platform' => 'ios',
            'events' => [['name' => 'app_open']],
        ]);

        // Identical responses. A caller must not be able to use this to work out
        // which app ids are real.
        $this->assertSame($hit->status(), $miss->status());
        $this->assertSame($hit->content(), $miss->content());
        $this->assertSame(204, $hit->status());
    }

    #[Test]
    public function it_rejects_events_outside_the_contract(): void
    {
        $app = $this->makeApp();

        $this->report($app, [['name' => 'purchase_completed']])->assertStatus(422);
        $this->assertSame(0, $this->counters($app)->count());
    }

    #[Test]
    public function it_ignores_an_action_detail_that_is_not_a_real_action(): void
    {
        $app = $this->makeApp();

        // Passes request validation — it is a short string — and is rejected by
        // the recorder, which checks it against the action vocabulary. If this
        // ever stores a row, the actions dimension is unbounded.
        $this->report($app, [['name' => 'action', 'detail' => 'runShellCommand']])
            ->assertNoContent();

        $this->assertSame(0, $this->counters($app)->count());
    }

    #[Test]
    public function it_ignores_a_detail_on_an_event_that_has_none(): void
    {
        $app = $this->makeApp();

        $this->report($app, [['name' => 'app_open', 'detail' => 'made_up']])->assertNoContent();

        $this->assertSame(0, $this->counters($app)->count());
    }

    #[Test]
    public function it_ignores_a_screen_id_that_is_not_shaped_like_one(): void
    {
        $app = $this->makeApp();

        $this->report($app, [['name' => 'screen_view', 'detail' => '../../etc/passwd']]);
        $this->report($app, [['name' => 'screen_view', 'detail' => '']]);

        $this->assertSame(0, $this->counters($app)->count());
    }

    #[Test]
    public function it_caps_how_many_rows_one_app_can_create_in_a_day(): void
    {
        $app = $this->makeApp();

        // The disk-exhaustion attack: thousands of invented screen ids. Sent in
        // batches because the endpoint bounds a single request, so the only way
        // to grow the table is to keep coming back.
        for ($batch = 0; $batch < 8; $batch++) {
            $events = [];
            for ($i = 0; $i < EventRecorder::MAX_BATCH; $i++) {
                $events[] = ['name' => 'screen_view', 'detail' => "scr_junk{$batch}_{$i}"];
            }
            $this->report($app, $events);
        }

        $rows = $this->counters($app);

        // 400 distinct ids offered. The cap bounds rows whose detail a caller
        // chose; the overflow buckets are a constant on top of it, so the
        // ceiling is the cap plus one row per event name per platform.
        $ceiling = EventRecorder::DISTINCT_ROWS_PER_DAY
            + count(Contract::vocabulary('eventNames')) * 2;

        $this->assertLessThanOrEqual($ceiling, $rows->count());

        // And the views past the cap were still counted, just not broken down —
        // a merchant should not silently lose traffic because of our limit.
        $overflow = $rows->firstWhere('detail', EventRecorder::OVERFLOW_DETAIL);
        $this->assertNotNull($overflow, 'Views past the cap were dropped rather than bucketed.');
        $this->assertGreaterThan(0, (int) $overflow->count);
    }

    #[Test]
    public function a_full_day_does_not_block_counting_screens_it_already_knows(): void
    {
        $app = $this->makeApp();

        $this->report($app, [['name' => 'screen_view', 'detail' => 'scr_home']]);

        for ($batch = 0; $batch < 6; $batch++) {
            $events = [];
            for ($i = 0; $i < EventRecorder::MAX_BATCH; $i++) {
                $events[] = ['name' => 'screen_view', 'detail' => "scr_junk{$batch}_{$i}"];
            }
            $this->report($app, $events);
        }

        // The real screen keeps counting after the cap is hit. Incrementing an
        // existing row adds no cardinality, so refusing it would punish the
        // merchant for someone else's flood.
        $this->report($app, [['name' => 'screen_view', 'detail' => 'scr_home', 'count' => 5]]);

        $home = $this->counters($app)->firstWhere('detail', 'scr_home');
        $this->assertSame(6, (int) $home->count);
    }

    #[Test]
    public function it_rejects_an_oversized_batch(): void
    {
        $app = $this->makeApp();

        $events = array_fill(0, EventRecorder::MAX_BATCH + 1, ['name' => 'app_open']);

        $this->report($app, $events)->assertStatus(422);
        $this->assertSame(0, $this->counters($app)->count());
    }

    #[Test]
    public function it_rejects_an_absurd_count(): void
    {
        $app = $this->makeApp();

        $this->report($app, [['name' => 'app_open', 'count' => 999999]])->assertStatus(422);
        $this->report($app, [['name' => 'app_open', 'count' => 0]])->assertStatus(422);
        $this->report($app, [['name' => 'app_open', 'count' => -5]])->assertStatus(422);

        $this->assertSame(0, $this->counters($app)->count());
    }

    #[Test]
    public function it_rejects_an_unknown_platform(): void
    {
        $app = $this->makeApp();

        $this->report($app, [['name' => 'app_open']], 'windows_phone')->assertStatus(422);
    }

    #[Test]
    public function the_phone_does_not_choose_the_day(): void
    {
        $app = $this->makeApp();

        // A client-supplied date would let anyone write into next year, so the
        // field does not exist. Sending one must not change where this lands.
        $this->postJson("/api/public/apps/{$app->getKey()}/events", [
            'platform' => 'ios',
            'events' => [['name' => 'app_open', 'day' => '2099-01-01']],
        ])->assertNoContent();

        $this->assertSame(
            now('UTC')->toDateString(),
            substr((string) $this->counters($app)->first()->day, 0, 10),
        );
    }

    // ------------------------------------------------------------------ read

    private function owner(App $app): User
    {
        return User::factory()->create(['organization_id' => $app->organization_id]);
    }

    #[Test]
    public function the_panel_sees_its_own_numbers(): void
    {
        $app = $this->makeApp();
        $this->report($app, [
            ['name' => 'app_open', 'count' => 4],
            ['name' => 'screen_view', 'detail' => 'scr_home', 'count' => 9],
            ['name' => 'action', 'detail' => 'openUrl', 'count' => 2],
        ]);

        $response = $this->actingAs($this->owner($app))
            ->getJson("/api/apps/{$app->getKey()}/analytics?days=7")
            ->assertOk();

        $response->assertJsonPath('data.totals.opens', 4);
        $response->assertJsonPath('data.totals.screenViews', 9);
        $response->assertJsonPath('data.totals.actions', 2);
        $response->assertJsonPath('data.range.days', 7);

        // Seven days, including the six with nothing in them. A chart that
        // skips empty days draws a flat line through an outage.
        $this->assertCount(7, $response->json('data.series'));
        $this->assertSame(4, $response->json('data.series.6.opens'));
        $this->assertSame(0, $response->json('data.series.0.opens'));
    }

    #[Test]
    public function it_names_screens_the_way_the_merchant_named_them(): void
    {
        $app = $this->makeApp();
        AppConfig::create([
            'app_id' => $app->getKey(),
            'schema_version' => 1,
            'config' => ['screens' => [['id' => 'scr_home', 'title' => 'Our Menu', 'sections' => []]]],
        ]);

        $this->report($app, [
            ['name' => 'screen_view', 'detail' => 'scr_home', 'count' => 5],
            ['name' => 'screen_view', 'detail' => 'scr_gone', 'count' => 2],
        ]);

        $response = $this->actingAs($this->owner($app))
            ->getJson("/api/apps/{$app->getKey()}/analytics")
            ->assertOk();

        $screens = collect($response->json('data.topScreens'));

        $this->assertSame('Our Menu', $screens->firstWhere('screenId', 'scr_home')['title']);
        // A screen that was viewed and later deleted. An unreadable ULID helps
        // nobody; saying what happened does.
        $this->assertSame('Deleted screen', $screens->firstWhere('screenId', 'scr_gone')['title']);
        // Busiest first.
        $this->assertSame('scr_home', $screens->first()['screenId']);
    }

    #[Test]
    public function it_does_not_call_a_screen_deleted_when_it_has_no_config_to_check(): void
    {
        // A published app whose draft was never saved. Every screen id would
        // otherwise be reported as deleted, which is a claim we cannot support
        // — there is simply nothing to look the name up in.
        $app = $this->makeApp();
        $this->report($app, [['name' => 'screen_view', 'detail' => 'scr_home', 'count' => 3]]);

        $response = $this->actingAs($this->owner($app))
            ->getJson("/api/apps/{$app->getKey()}/analytics")
            ->assertOk();

        $this->assertSame('Unnamed screen', $response->json('data.topScreens.0.title'));
    }

    #[Test]
    public function it_falls_back_to_the_published_config_for_screen_names(): void
    {
        $app = $this->makeApp();

        // No draft, but a published version — which is the config the counted
        // views actually came from.
        $app->versions()->create([
            'version' => 1,
            'schema_version' => 1,
            'config' => ['screens' => [['id' => 'scr_home', 'title' => 'Front Page', 'sections' => []]]],
        ]);

        $this->report($app, [['name' => 'screen_view', 'detail' => 'scr_home', 'count' => 3]]);

        $response = $this->actingAs($this->owner($app))
            ->getJson("/api/apps/{$app->getKey()}/analytics")
            ->assertOk();

        $this->assertSame('Front Page', $response->json('data.topScreens.0.title'));
    }

    #[Test]
    public function it_compares_against_the_period_before(): void
    {
        $app = $this->makeApp();
        $this->report($app, [['name' => 'app_open', 'count' => 10]]);

        // A row in the window immediately before a 7-day range, written
        // directly because the endpoint only ever stamps today.
        DB::table('app_event_daily')->insert([
            'app_id' => $app->getKey(),
            'day' => now('UTC')->subDays(8)->toDateString(),
            'event' => 'app_open',
            'detail' => '',
            'platform' => 'ios',
            'count' => 4,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->owner($app))
            ->getJson("/api/apps/{$app->getKey()}/analytics?days=7")
            ->assertOk();

        $response->assertJsonPath('data.totals.opens', 10);
        $response->assertJsonPath('data.previous.opens', 4);
    }

    #[Test]
    public function the_previous_window_does_not_bleed_into_the_current_one(): void
    {
        $app = $this->makeApp();
        $this->report($app, [['name' => 'app_open', 'count' => 3]]);

        // Today must be counted once, in `totals`, and never in `previous` —
        // an off-by-one on the boundary would double-count the newest day and
        // make every app look like it is holding steady.
        $response = $this->actingAs($this->owner($app))
            ->getJson("/api/apps/{$app->getKey()}/analytics?days=30")
            ->assertOk();

        $response->assertJsonPath('data.totals.opens', 3);
        $response->assertJsonPath('data.previous.opens', 0);
    }

    #[Test]
    public function another_organisation_gets_a_404_not_a_403(): void
    {
        $mine = $this->makeApp();
        $theirs = $this->makeApp('Rival Pizza');

        $this->report($theirs, [['name' => 'app_open', 'count' => 50]]);

        // 404, not 403: confirming an app exists but is not yours is still a
        // disclosure. Same rule as everywhere else in this API.
        $this->actingAs($this->owner($mine))
            ->getJson("/api/apps/{$theirs->getKey()}/analytics")
            ->assertNotFound();
    }

    #[Test]
    public function it_requires_a_login(): void
    {
        $app = $this->makeApp();

        $this->getJson("/api/apps/{$app->getKey()}/analytics")->assertUnauthorized();
    }

    #[Test]
    public function an_app_with_no_traffic_reports_zeroes_rather_than_failing(): void
    {
        $app = $this->makeApp();

        $response = $this->actingAs($this->owner($app))
            ->getJson("/api/apps/{$app->getKey()}/analytics?days=30")
            ->assertOk();

        $response->assertJsonPath('data.totals.opens', 0);
        $this->assertCount(30, $response->json('data.series'));
        $this->assertSame([], $response->json('data.topScreens'));
    }

    #[Test]
    public function it_only_accepts_the_ranges_it_offers(): void
    {
        $app = $this->makeApp();
        $owner = $this->owner($app);

        // A range is a table scan, so it is a closed list rather than a number
        // someone can set to 100000.
        foreach (['9999', 'all', '-1', ''] as $bad) {
            $this->actingAs($owner)
                ->getJson("/api/apps/{$app->getKey()}/analytics?days={$bad}")
                ->assertOk()
                ->assertJsonPath('data.range.days', 30);
        }
    }
}
