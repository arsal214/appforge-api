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

class AnalyticsController extends Controller
{
    private const RANGES = [7, 30, 90];

    public function show(Request $request, string $app): JsonResponse
    {
        $model = $request->user()->organization->apps()->findOrFail($app);

        $days = (int) $request->query('days', '30');
        if (! in_array($days, self::RANGES, true)) {
            $days = 30;
        }

        $until = Carbon::now('UTC')->startOfDay();
        $since = $until->copy()->subDays($days - 1);

        $rows = DB::table('app_event_daily')
            ->where('app_id', $model->getKey())
            ->whereBetween('day', [$since->toDateString(), $until->toDateString()])
            ->get(['day', 'event', 'detail', 'platform', 'count']);

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

                    'asOf' => Carbon::now('UTC')->toDateTimeString(),
                ],
                'basis' => 'counts',
                'note' => 'These are event counts, not people. One customer opening the app three times counts as three opens.',
            ],
        ]);
    }

    private function totals(Collection $rows): array
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

    private function series(Collection $rows, Carbon $since, int $days): array
    {
        $byDay = $rows->groupBy(fn ($row) => (string) $row->day);

        $series = [];
        for ($offset = 0; $offset < $days; $offset++) {
            $day = $since->copy()->addDays($offset)->toDateString();

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

    private function topScreens(Collection $rows, ClientApp $model): array
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

                    $config === null => 'Unnamed screen',

                    default => 'Deleted screen',
                },
                'count' => (int) $group->sum('count'),
            ])
            ->sortByDesc('count')
            ->take(10)
            ->values()
            ->all();
    }

    private function topActions(Collection $rows): array
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

    private function platforms(Collection $rows): array
    {
        $sum = fn (string $platform) => (int) $rows
            ->where('platform', $platform)
            ->where('event', 'app_open')
            ->sum('count');

        return ['ios' => $sum('ios'), 'android' => $sum('android')];
    }
}
