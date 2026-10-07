<?php

namespace App\Providers;

use App\Support\Vault\CredentialVault;
use App\Support\Vault\KeyManagement;
use App\Support\Vault\LocalKeyManagement;
use App\Support\Vault\VaultException;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the vault, and refuses to wire a development one in production.
 *
 * That refusal is the point of this class. The local key manager holds the
 * master key in the same environment as the ciphertext, which is fine on a
 * laptop and indefensible with a real customer's Shopify token. Left to
 * configuration alone, that difference is one missing environment variable away
 * from going unnoticed — so it fails at boot instead, loudly, where it cannot
 * be ignored.
 */
class VaultServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(KeyManagement::class, function () {
            $driver = config('vault.driver', 'local');

            $manager = match ($driver) {
                'local' => new LocalKeyManagement($this->localMasterKey()),
                // 'kms' => new AwsKeyManagement(...) — deliberately absent.
                // See the vault README: an untested KMS client is worse than
                // none, because it looks finished.
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
