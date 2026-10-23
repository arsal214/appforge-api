<?php

namespace App\Support\Vault;

final class LocalKeyManagement implements KeyManagement
{
    private const CIPHER = 'aes-256-gcm';

    public function __construct(private readonly string $masterKey)
    {

        if (strlen($this->masterKey) !== 32) {
            throw new VaultException(
                'The vault master key must be exactly 32 bytes. '.
                'Generate one with: php artisan appforge:vault-key',
            );
        }
    }

    public function generateDataKey(array $context): DataKey
    {

        $plaintext = random_bytes(32);

        return new DataKey(
            plaintext: $plaintext,
            wrapped: $this->seal($plaintext, $this->masterKey, $context),
        );
    }

    public function unwrapDataKey(string $wrapped, array $context): string
    {
        return $this->open($wrapped, $this->masterKey, $context);
    }

    public function id(): string
    {
        return 'local';
    }

    public function describe(): string
    {
        return 'Local development key (not a KMS)';
    }

    public function isProductionGrade(): bool
    {
        return false;
    }

    public static function seal(string $plaintext, string $key, array $context): string
    {

        $iv = random_bytes(12);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            self::canonical($context),
            16,
        );

        if ($ciphertext === false) {
            throw new VaultException('Encryption failed.');
        }

        return base64_encode($iv.$tag.$ciphertext);
    }

    public static function open(string $sealed, string $key, array $context): string
    {
        $raw = base64_decode($sealed, true);

        if ($raw === false || strlen($raw) < 29) {

            throw new VaultException('Could not decrypt.');
        }

        $plaintext = openssl_decrypt(
            substr($raw, 28),
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            substr($raw, 0, 12),
            substr($raw, 12, 16),
            self::canonical($context),
        );

        if ($plaintext === false) {

            throw new VaultException('Could not decrypt.');
        }

        return $plaintext;
    }

    private static function canonical(array $context): string
    {
        ksort($context);

        return json_encode($context, JSON_THROW_ON_ERROR);
    }
}
