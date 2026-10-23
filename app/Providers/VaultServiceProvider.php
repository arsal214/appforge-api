<?php

namespace App\Providers;

use App\Support\Vault\CredentialVault;
use App\Support\Vault\KeyManagement;
use App\Support\Vault\LocalKeyManagement;
use App\Support\Vault\VaultException;
use Illuminate\Support\ServiceProvider;

class VaultServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(KeyManagement::class, function () {
            $driver = config('vault.driver', 'local');

            $manager = match ($driver) {
                'local' => new LocalKeyManagement($this->localMasterKey()),

                default => throw new VaultException(
                    "Unknown vault driver [{$driver}]. Only 'local' exists today.",
                ),
            };

            if ($this->app->environment('production') && ! $manager->isProductionGrade()) {
                throw new VaultException(
                    'The vault is configured with a development key manager. '.
                    'Real customer credentials must be protected by a KMS — refusing to start.',
                );
            }

            return $manager;
        });

        $this->app->singleton(
            CredentialVault::class,
            fn ($app) => new CredentialVault($app->make(KeyManagement::class)),
        );
    }

    private function localMasterKey(): string
    {
        $configured = (string) config('vault.local_key', '');

        if ($configured === '') {
            throw new VaultException(
                'VAULT_LOCAL_KEY is not set. Generate one with: '.
                'php artisan appforge:vault-key',
            );
        }

        $decoded = base64_decode($configured, true);

        if ($decoded === false || strlen($decoded) !== 32) {
            throw new VaultException(
                'VAULT_LOCAL_KEY must be 32 base64-encoded bytes. '.
                'Generate one with: php artisan appforge:vault-key',
            );
        }

        return $decoded;
    }
}
