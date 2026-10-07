<?php

namespace App\Support\Analytics;

use AppForge\Schema\Contract;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Turning a batch of reported events into counters.
 *
 * This is the write half of analytics and it is reached through an
 * **unauthenticated** endpoint, so it is a security boundary in the same sense
 * ConfigValidator is. Three things can go wrong when anyone on the internet can
 * post here, and each has an answer:
 *
 *  1. **Inflated numbers.** Someone who knows an app id can add to its counts.
 *     Unavoidable without device attestation (Play Integrity / App Attest), and
 *     the same residual risk the device endpoint already carries. The damage is
 *     a wrong chart, not a leak. Documented rather than pretended away.
 *
 *  2. **An unbounded table.** This is the real one. A row exists per distinct
 *     `detail`, so a caller sending ten thousand made-up screen ids creates ten
 *     thousand rows per day. Closed two ways: `detail` is validated against the
 *     shape the event allows, which bounds `action` and `push_open` and
 *     `app_open` completely; and `DISTINCT_ROWS_PER_DAY` caps the rest, since a
 *     screen id is a ULID and therefore unbounded on its own.
 *
 *  3. **A phone with a wrong clock.** The day is stamped by the server. A
 *     client-supplied date would let anyone write into next year, and clamping
 *     it is more machinery than the honest cost of the alternative: events
 *     buffered offline are counted on the day they arrive, not the day they
 *     happened. The shell flushes at launch and at background, so that is at
 *     most one session of drift.
 */
class EventRecorder
{
    /**
     * How many distinct counter rows one app may create in one day.
     *
     * Reached only by `screen_view`, whose detail is a screen id and therefore
     * has no vocabulary to check against. A real app has tens of screens across
     * two platforms; two hundred is far above honest use and far below anything
     * that threatens a disk. Over the cap, events are counted against the
     * `OVERFLOW_DETAIL` bucket rather than dropped — a merchant should see that
     * the views happened even when we stop breaking them down.
     *
     * This bounds rows whose detail a *caller* chose. The overflow buckets sit
     * on top of it, so the true ceiling is this plus one row per event name per
     * platform — a constant, and currently eight.
     */
    public const DISTINCT_ROWS_PER_DAY = 200;

    /** Where views land once an app is over its daily cardinality cap. */
    public const OVERFLOW_DETAIL = '_other';

    /** Most events one request may carry, after grouping. */
    public const MAX_BATCH = 50;

    /** Largest count a single reported event may claim. */
    public const MAX_COUNT = 1000;

    /**
     * @param  list<array{name: string, detail?: string|null, count?: int|null}>  $events
     * @return int how many events were counted; the rest were rejected
     */
    public function record(string $appId, string $platform, array $events): int
    {
        $day = Carbon::now('UTC')->toDateString();

        // Grouped before anything touches the database. A shell reporting forty
        // views of one screen becomes one row with count 40, not forty writes.
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

    /**
     * `event|detail`, or null if the event is not something we record.
     *
     * Rejecting rather than coercing: an event we do not understand is a shell
     * that is newer than this server, or someone poking at the endpoint. Both
     * are better ignored than guessed at.
     */
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
            // A tap on a button, keyed by what kind of thing it did. Closed
            // vocabulary, so this dimension can never grow past a handful.
            'action' => in_array($detail, Contract::vocabulary('actionTypes'), true)
                ? "action|{$detail}"
                : null,

            // A screen id. The one open-ended dimension — capped in `apply`.
            'screen_view' => $detail !== '' && preg_match(Contract::pattern('id'), $detail) === 1
                ? "screen_view|{$detail}"
                : null,

            // Counting only. A detail here would be a caller inventing one.
            'app_open', 'push_open' => $detail === '' ? "{$name}|" : null,

            default => null,
        };
    }

    /**
     * @param  array<string, int>  $grouped
     */
    private function apply(string $appId, string $day, string $platform, array $grouped): int
    {
        // One query, before the loop: which rows does this app already have
        // today? An existing row is always safe to increment however full the
        // day is, because incrementing adds no cardinality.
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
                    // Over the cap: keep the total honest, lose the breakdown.
                    //
                    // The overflow row is created even though the day is full.
                    // Refusing it on cardinality grounds would mean it could
                    // never come into existence — it is only ever needed once
                    // the cap is already reached — and the views it exists to
                    // preserve would be silently dropped instead. It costs at
                    // most one row per event per platform, because the detail
                    // is a constant rather than something a caller supplies.
                    $detail = self::OVERFLOW_DETAIL;
                } else {
                    $room--;
                }
            }

            // Insert-then-increment rather than one upsert: Laravel's `upsert`
            // assigns values, and this needs `count = count + n`.
            // `insertOrIgnore` collides harmlessly when two phones report the
            // same screen in the same millisecond, and `increment` compiles to
            // `count = count + ?` — so neither step can lose a write to a
            // read-modify-write race between concurrent requests.
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
