<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Password;

class ResetCustomerPassword extends Command
{
    protected $signature = 'appforge:reset-password {email} {--link : print a reset link instead of setting a password}';

    protected $description = 'Unlock a customer who cannot receive the reset email.';

    public function handle(): int
    {
        $email = strtolower(trim((string) $this->argument('email')));
        $user = User::where('email', $email)->first();

        if (! $user) {
            $this->components->error("No account for {$email}.");

            return self::FAILURE;
        }

        if ($this->option('link')) {
            $panel = rtrim((string) config('app.frontend_url'), '/');
            $token = Password::broker()->createToken($user);

            $this->components->info('Give them this link. It works once and expires in an hour:');
            $this->line('');
            $this->line("{$panel}/reset-password?token={$token}&email=".urlencode($email));
            $this->line('');

            return self::SUCCESS;
        }

        $password = base64_encode(random_bytes(12));

        $user->forceFill(['password' => $password])->save();

        $user->tokens()->delete();

        $this->components->info("Set a new password for {$user->name} <{$email}>:");
        $this->line('');
        $this->line($password);
        $this->line('');
        $this->components->warn(
            'Say it out loud, do not paste it anywhere it is kept, and tell them to change it.'
        );

        return self::SUCCESS;
    }
}
