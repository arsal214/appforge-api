<?php

namespace App\Listeners;

use Laravel\Sanctum\Events\TokenAuthenticated;
use Laravel\Sanctum\PersonalAccessToken;

class SlideTokenExpiry
{
    private const SKIP_IF_WITHIN_MINUTES = 60;

    public function handle(TokenAuthenticated $event): void
    {
        $token = $event->token;

        if (! $token instanceof PersonalAccessToken) {
            return;
        }

        $minutes = (int) config('sanctum.idle_minutes');

        if ($minutes <= 0) {
            return;
        }

        $target = now()->addMinutes($minutes);

        if (
            $token->expires_at !== null
            && $token->expires_at->gt($target->copy()->subMinutes(self::SKIP_IF_WITHIN_MINUTES))
        ) {
            return;
        }

        $token->forceFill(['expires_at' => $target])->saveQuietly();
    }
}
