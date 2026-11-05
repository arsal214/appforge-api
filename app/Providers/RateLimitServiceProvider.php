<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class RateLimitServiceProvider extends ServiceProvider
{
    private const PANEL_PER_USER = 300;

    private const PANEL_PER_ORGANIZATION = 1200;

    public function boot(): void
    {
        RateLimiter::for('panel', function (Request $request): array {
            $user = $request->user();

            if ($user === null) {
                return [Limit::perMinute(60)->by('panel:ip:'.$request->ip())];
            }

            return [
                Limit::perMinute(self::PANEL_PER_USER)
                    ->by('panel:user:'.$user->getAuthIdentifier()),
                Limit::perMinute(self::PANEL_PER_ORGANIZATION)
                    ->by('panel:org:'.$user->organization_id),
            ];
        });

        $this->perApp('public-config', 120);
        $this->perApp('public-devices', 30);
        $this->perApp('public-events', 60);
        $this->perApp('public-orders', 20);
    }

    private function perApp(string $name, int $perMinute): void
    {
        RateLimiter::for($name, fn (Request $request): Limit => Limit::perMinute($perMinute)
            ->by($name.':'.($request->route('app') ?? 'none').':'.$request->ip()));
    }
}
