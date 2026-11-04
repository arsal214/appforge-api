<?php

namespace App\Providers;

use App\Listeners\SlideTokenExpiry;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Events\TokenAuthenticated;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        $this->refuseToSwallowMailInProduction();

        Event::listen(TokenAuthenticated::class, SlideTokenExpiry::class);

        ResetPassword::createUrlUsing(function (object $notifiable, string $token): string {
            $panel = rtrim((string) config('app.frontend_url'), '/');

            return $panel.'/reset-password?token='.$token
                .'&email='.urlencode($notifiable->getEmailForPasswordReset());
        });
    }

    private function refuseToSwallowMailInProduction(): void
    {
        if (! $this->app->environment('production')) {
            return;
        }

        $mailer = (string) config('mail.default');

        if (in_array($mailer, ['log', 'array'], true)) {
            throw new RuntimeException(
                "MAIL_MAILER is [{$mailer}], which delivers nothing. Orders "
                .'would be taken and the shop never told. Set a real mailer '
                .'before serving production traffic.'
            );
        }
    }
}
