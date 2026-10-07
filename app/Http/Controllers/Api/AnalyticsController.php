<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\App as ClientApp;
use App\Support\Analytics\EventRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What the panel shows on the Analytics page.
 *
 * Read-only, tenant-scoped the same way everything else is: the app is fetched
 * through the caller's own organisation, so another org's numbers are a 404
 * rather than a 403.
 *
 * Every number here is a sum of counters. There is no unique-visitor figure and
 * no retention curve, and that is a property of the storage design rather than
 * an unfinished feature — see the `app_event_daily` migration. The response
 * carries `basis` saying so, because a merchant who assumes "opens" means
 * "people" will make decisions on a number that does not mean what they think.
 */
class AnalyticsController extends Controller
{
    /** Windows the panel may ask for. Bounded so nobody can scan all history. */
    private const RANGES = [7, 30, 90];

    public function show(Request $request, string $app): JsonResponse
    {
        $model = $request->user()->organization->apps()->findOrFail($app);

        $days = (int) $request->query('days', '30');
        if (! in_array($days, self::RANGES, true)) {
            $days = 30;
        }

        // Inclusive of today, so `days=7` is a week ending now rather than a
        // week ending yesterday plus a partial day nobody asked for.
        $until = Carbon::now('UTC')->startOfDay();
        $since = $until->copy()->subDays($days - 1);

        $rows = DB::table('app_event_daily')
            ->where('app_id', $model->getKey())
            ->whereBetween('day', [$since->toDateString(), $until->toDateString()])
            ->get(['day', 'event', 'detail', 'platform', 'count']);

        // The window of equal length immediately before this one, so the panel
        // can say "up from" instead of leaving a merchant to guess whether a
        // number is good. Totals only — nobody needs last month's breakdown.
        $previousRows = DB::table('app_event_daily')
            ->where('app_id', $model->getKey())
            ->whereBetween('day', [
                $since->copy()->subDays($days)->toDateString(),
                $since->copy()->subDay()->toDateString(),
            ])
            ->get(['event', 'count']);

        return response()->json([
            'data' => [
                'range' => [
                    'days' => $days,
                    'since' => $since->toDateString(),
                    'until' => $until->toDateString(),
                    // Stated, not implied. The chart axis is UTC days and a
                    // merchant reading it as local time will misread the edges.
                    'timezone' => 'UTC',
                ],
                'totals' => $this->totals($rows),
                'previous' => $this->totals($previousRows),
                'series' => $this->series($rows, $since, $days),
                'topScreens' => $this->topScreens($rows, $model),
                'topActions' => $this->topActions($rows),
                'platforms' => $this->platforms($rows),
                'installs' => [
                    'total' => $model->devices()->count(),
                    // Installs are a live count, not a daily series — we have
                    // never stored a history of it, and inventing one from the
                    // current table would be a chart of when we happened to
                    // look rather than of anything that happened.
                    'asOf' => Carbon::now('UTC')->toDateTimeString(),
                ],
                'basis' => 'counts',
                'note' => 'These are event counts, not people. One customer opening the app three times counts as three opens.',
            ],
        ]);
    }

    /** @param  Collection<int, object>  $rows */
    private function totals($rows): array
    {
        $sum = fn (string $event) => (int) $rows
            ->where('event', $event)
            ->sum('count');

        return [
            'opens' => $sum('app_open'),
            'screenViews' => $sum('screen_view'),
            'actions' => $sum('action'),
            'pushOpens' => $sum('push_open'),
        ];
    }

    /**
     * One entry per day in the range, including days with nothing.
     *
     * Filled here rather than in the panel: a chart that silently skips empty
     * days draws a flat line through a week the app was down and makes it look
     * like steady use.
     *
     * @param  Collection<int, object>  $rows
     */
    private function series($rows, Carbon $since, int $days): array
    {
        $byDay = $rows->groupBy(fn ($row) => (string) $row->day);

        $series = [];
        for ($offset = 0; $offset < $days; $offset++) {
            $day = $since->copy()->addDays($offset)->toDateString();
            // MySQL hands back `Y-m-d` for a date column, but a driver that
            // returns a datetime would never match the key — so match on the
            // date part rather than trusting the format.
            $dayRows = $byDay->first(
                fn ($group, $key) => str_starts_with((string) $key, $day),
            ) ?? collect();

            $series[] = [
                'day' => $day,
                'opens' => (int) $dayRows->where('event', 'app_open')->sum('count'),
                'screenViews' => (int) $dayRows->where('event', 'screen_view')->sum('count'),
                'actions' => (int) $dayRows->where('event', 'action')->sum('count'),
            ];
        }

        return $series;
    }

    /**
     * Which screens got looked at, named the way the merchant named them.
     *
     * The counter table stores screen ids, because a title can be renamed and a
     * rollup cannot be rewritten. Titles are resolved at read time from the
     * current draft — so renaming a screen relabels its history, which is what
     * someone would expect, rather than splitting it into two rows.
     *
     * Falls back to the newest published version when there is no draft. The
     * views being counted came from a *published* app, so the published config
     * is the more likely place to find the name, and a draft that has been
     * deleted must not erase the labels for traffic that already happened.
     *
     * @param  Collection<int, object>  $rows
     */
    private function topScreens($rows, ClientApp $model): array
    {
        $config = $model->config?->config
            ?? $model->versions()->latest('version')->first()?->config;

        $titles = [];
        foreach ($config['screens'] ?? [] as $screen) {
            if (isset($screen['id'], $screen['title'])) {
                $titles[$screen['id']] = $screen['title'];
            }
        }

        return $rows
            ->where('event', 'screen_view')
            ->groupBy('detail')
            ->map(fn ($group, $detail) => [
                'screenId' => (string) $detail,
                'title' => match (true) {
                    $detail === EventRecorder::OVERFLOW_DETAIL => 'Everything else',
                    isset($titles[$detail]) => $titles[$detail],
                    // Nothing to look the name up in. "Deleted" would be a
                    // claim we cannot support — the screen may well still
                    // exist and simply have no saved config yet.
                    $config === null => 'Unnamed screen',
                    // Now it genuinely is gone: there is a config, and this id
                    // is not in it. Saying so beats an unreadable ULID.
                    default => 'Deleted screen',
                },
                'count' => (int) $group->sum('count'),
            ])
            ->sortByDesc('count')
            ->take(10)
            ->values()
            ->all();
    }

    /** @param  Collection<int, object>  $rows */
    private function topActions($rows): array
    {
        return $rows
            ->where('event', 'action')
            ->groupBy('detail')
            ->map(fn ($group, $detail) => [
                'type' => (string) $detail,
                'count' => (int) $group->sum('count'),
            ])
            ->sortByDesc('count')
            ->values()
            ->all();
    }

    /** @param  Collection<int, object>  $rows */
    private function platforms($rows): array
    {
        $sum = fn (string $platform) => (int) $rows
            ->where('platform', $platform)
            ->where('event', 'app_open')
            ->sum('count');

        return ['ios' => $sum('ios'), 'android' => $sum('android')];
    }
}
