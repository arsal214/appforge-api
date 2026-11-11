<?php

namespace App\Support\Analytics;

use App\Models\SiteEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SiteEventRecorder
{
    // A closed list. An unknown name is dropped rather than stored, so a
    // forged event cannot fill the table. The table counts and nothing
    // else: no visitor, no address, no session.
    public const EVENTS = [
        'preview_started',
        'preview_seen',
        'preview_failed',
        'signup_started',
    ];

    public function record(string $event): bool
    {
        if (! in_array($event, self::EVENTS, true)) {
            return false;
        }

        $day = Carbon::now('UTC')->toDateString();
        $now = Carbon::now();

        DB::table('site_event_daily')->insertOrIgnore([
            'id' => 'sev_'.strtolower((string) Str::ulid()),
            'day' => $day,
            'event' => $event,
            'count' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('site_event_daily')
            ->where('day', $day)
            ->where('event', $event)
            ->increment('count', 1, ['updated_at' => $now]);

        return true;
    }

    public static function since(int $days): array
    {
        return SiteEvent::query()
            ->where('day', '>=', Carbon::now('UTC')->subDays($days)->toDateString())
            ->groupBy('event')
            ->selectRaw('event, sum(`count`) as total')
            ->pluck('total', 'event')
            ->all();
    }
}
