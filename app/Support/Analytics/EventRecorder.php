<?php

namespace App\Support\Analytics;

use AppForge\Schema\Contract;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class EventRecorder
{
    public const DISTINCT_ROWS_PER_DAY = 200;

    public const OVERFLOW_DETAIL = '_other';

    public const MAX_BATCH = 50;

    public const MAX_COUNT = 1000;

    public function record(string $appId, string $platform, array $events): int
    {
        $day = Carbon::now('UTC')->toDateString();

        $grouped = [];
        foreach (array_slice($events, 0, self::MAX_BATCH) as $event) {
            $key = $this->keyFor($event);
            if ($key === null) {
                continue;
            }

            $count = (int) ($event['count'] ?? 1);
            if ($count < 1) {
                continue;
            }

            $grouped[$key] = ($grouped[$key] ?? 0) + min($count, self::MAX_COUNT);
        }

        if ($grouped === []) {
            return 0;
        }

        return $this->apply($appId, $day, $platform, $grouped);
    }

    private function keyFor(array $event): ?string
    {
        $name = $event['name'] ?? null;
        if (! is_string($name) || ! in_array($name, Contract::vocabulary('eventNames'), true)) {
            return null;
        }

        $detail = $event['detail'] ?? null;
        if ($detail !== null && ! is_string($detail)) {
            return null;
        }
        $detail = $detail === null ? '' : trim($detail);

        return match ($name) {

            'action' => in_array($detail, Contract::vocabulary('actionTypes'), true)
                ? "action|{$detail}"
                : null,

            'screen_view' => $detail !== '' && preg_match(Contract::pattern('id'), $detail) === 1
                ? "screen_view|{$detail}"
                : null,

            'app_open', 'push_open' => $detail === '' ? "{$name}|" : null,

            default => null,
        };
    }

    private function apply(string $appId, string $day, string $platform, array $grouped): int
    {

        $known = DB::table('app_event_daily')
            ->where('app_id', $appId)
            ->where('day', $day)
            ->get(['event', 'detail', 'platform'])
            ->map(fn ($row) => "{$row->event}|{$row->detail}|{$row->platform}")
            ->flip();

        $room = self::DISTINCT_ROWS_PER_DAY - $known->count();
        $counted = 0;
        $now = Carbon::now();

        foreach ($grouped as $key => $count) {
            [$event, $detail] = explode('|', $key, 2);

            if (! $known->has("{$event}|{$detail}|{$platform}")) {
                if ($room <= 0) {

                    $detail = self::OVERFLOW_DETAIL;
                } else {
                    $room--;
                }
            }

            DB::table('app_event_daily')->insertOrIgnore([
                'app_id' => $appId,
                'day' => $day,
                'event' => $event,
                'detail' => $detail,
                'platform' => $platform,
                'count' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('app_event_daily')
                ->where('app_id', $appId)
                ->where('day', $day)
                ->where('event', $event)
                ->where('detail', $detail)
                ->where('platform', $platform)
                ->increment('count', $count, ['updated_at' => $now]);

            $known->put("{$event}|{$detail}|{$platform}", true);
            $counted += $count;
        }

        return $counted;
    }
}
