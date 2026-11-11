<?php

namespace Tests\Feature;

use App\Models\SiteEvent;
use App\Support\Analytics\SiteEventRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SiteEventTest extends TestCase
{
    use RefreshDatabase;

    private function send(string $event): TestResponse
    {
        return $this->postJson('/api/public/site-events', ['event' => $event]);
    }

    #[Test]
    public function an_event_is_counted(): void
    {
        $this->send('preview_started')->assertNoContent();

        $row = SiteEvent::sole();

        $this->assertSame('preview_started', $row->event);
        $this->assertSame(1, $row->count);
        $this->assertSame(now('UTC')->toDateString(), $row->day->toDateString());
    }

    #[Test]
    public function the_same_event_twice_in_a_day_is_one_row_and_two_counts(): void
    {
        foreach (range(1, 5) as $ignored) {
            $this->send('preview_started')->assertNoContent();
        }

        $this->assertSame(1, SiteEvent::count());
        $this->assertSame(5, SiteEvent::sole()->count);
    }

    #[Test]
    public function different_events_are_counted_apart(): void
    {
        $this->send('preview_started')->assertNoContent();
        $this->send('preview_started')->assertNoContent();
        $this->send('preview_failed')->assertNoContent();

        $this->assertSame(2, SiteEvent::where('event', 'preview_started')->sole()->count);
        $this->assertSame(1, SiteEvent::where('event', 'preview_failed')->sole()->count);
    }

    #[Test]
    public function a_name_outside_the_funnel_is_refused(): void
    {
        $this->send('whatever_i_like')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['event']);

        $this->assertSame(0, SiteEvent::count());
    }

    #[Test]
    public function the_recorder_refuses_an_unknown_name_on_its_own(): void
    {
        $recorder = new SiteEventRecorder;

        $this->assertFalse($recorder->record('not_a_funnel_step'));
        $this->assertTrue($recorder->record('signup_started'));
        $this->assertSame(1, SiteEvent::count());
    }

    #[Test]
    public function nothing_about_a_visitor_is_stored(): void
    {
        foreach (['ip_address', 'session_id', 'user_id', 'user_agent', 'organization_id'] as $column) {
            $this->assertFalse(
                Schema::hasColumn('site_event_daily', $column),
                "site_event_daily.{$column} exists, so this is no longer anonymous.",
            );
        }
    }

    #[Test]
    public function the_counts_can_be_read_back_for_a_window(): void
    {
        $this->send('preview_started')->assertNoContent();
        $this->send('preview_started')->assertNoContent();
        $this->send('signup_started')->assertNoContent();

        $totals = SiteEventRecorder::since(7);

        $this->assertSame(2, (int) $totals['preview_started']);
        $this->assertSame(1, (int) $totals['signup_started']);
        $this->assertArrayNotHasKey('preview_failed', $totals);
    }

    #[Test]
    public function the_endpoint_needs_no_account(): void
    {
        $this->send('preview_started')->assertNoContent();

        $this->assertSame(1, SiteEvent::count());
    }
}
