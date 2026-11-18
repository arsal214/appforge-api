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

        RateLimiter::for('public-waitlist', fn (Request $request): Limit => Limit::perMinute(5)
            ->by('public-waitlist:'.$request->ip()));

        RateLimiter::for('catalogue-import', fn (Request $request): Limit => Limit::perMinute(6)
            ->by('catalogue-import:'.($request->user()?->organization_id ?? $request->ip())));

        RateLimiter::for('public-site-events', fn (Request $request): Limit => Limit::perMinute(60)
            ->by('public-site-events:'.$request->ip()));

        RateLimiter::for('public-accounts', function (Request $request): array {
            $app = $request->route('app') ?? 'none';
            $limits = [Limit::perMinute(30)->by("public-accounts:{$app}:".$request->ip())];

            // A second, tighter bucket per email: guessing one person's
            // password stays slow however many addresses it comes from.
            if (is_string($request->input('email'))) {
                $email = sha1(strtolower(trim($request->input('email'))));
                $limits[] = Limit::perMinute(5)->by("public-accounts:{$app}:{$email}");
            }

            return $limits;
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
