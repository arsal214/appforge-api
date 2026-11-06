<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Background work
|--------------------------------------------------------------------------
|
| All of this needs exactly one thing on the server: a cron calling
| `php artisan schedule:run` every minute. There is deliberately no separate
| always-on queue worker to supervise — at this size that is a second thing
| to install, watch and restart, and the only thing waiting in the queue is
| an email to a shop owner, which can bear being up to a minute late.
|
| Swap the first entry for a supervised `queue:work` the day a job needs to
| start in seconds rather than within the minute.
|
| Until that cron exists, nothing below runs: emails sit in `jobs` and a
| closed account stays in its wind-down. Both err the safe way, and both are
| invisible, which is exactly why this comment is here.
|
*/

// Drains whatever is waiting and exits, well inside the minute so the next
// run never lands on top of this one.
Schedule::command('queue:work --stop-when-empty --max-time=50 --tries=3')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();

// Erases the accounts whose 30 days have run out.
Schedule::command('appforge:close-accounts')->dailyAt('03:00');

// A failed job nobody has looked at in a month is noise, not a lead.
Schedule::command('queue:prune-failed --hours=720')->weeklyOn(1, '03:30');
