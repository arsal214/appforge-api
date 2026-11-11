<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('queue:work --stop-when-empty --max-time=50 --tries=3')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('appforge:close-accounts')->dailyAt('03:00');

Schedule::command('appforge:empty-bin')->dailyAt('03:10');

Schedule::command('queue:prune-failed --hours=720')->weeklyOn(1, '03:30');
