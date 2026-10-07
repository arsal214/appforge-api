<?php

namespace App\Support\Vault;

/**
 * Envelope encryption with a master key held in the environment.
 *
 * **This is for development, and it says so.** `isProductionGrade()` returns
 * false, the panel surfaces that, and `VaultServiceProvider` refuses to boot it
 * in production. Holding the master key in the same process that holds the
 * ciphertext gives up the main thing a KMS buys: anyone who can read the
 * environment can decrypt everything.
 *
 * What it *does* do is exercise the real envelope structure — per-tenant data
 * keys, wrapped keys stored beside ciphertext, an authenticated context binding
 * — so swapping in a real KMS changes this one class and nothing else. The
 * shape is the part worth getting right now; the key custody is the part that
 * needs an AWS account.
 *
 * **The production driver is deliberately not written.** An AWS KMS client that
 * has never been run against AWS is worse than an absence: it looks finished,
 * and the first time anyone finds out otherwise is with a customer's Shopify
 * token in hand. See `docs` in the vault README for what it has to do.
 */
final class LocalKeyManagement implements KeyManagement
{
    private const CIPHER = 'aes-256-gcm';

    public function __construct(private readonly string $masterKey)
    {
        // 32 bytes, because AES-256 needs 32 bytes. A short key here would
        // silently weaken every credential in the system.
        if (strlen($this->masterKey) !== 32) {
            throw new VaultException(
                'The vault master key must be exactly 32 bytes. '.
                'Generate one with: php artisan appforge:vault-key',
            );
        }
    }

    public function generateDataKey(array $context): DataKey
    {
        // random_bytes, not rand/mt_rand/uniqid. This is the only source of
        // randomness in the system where a weak one is catastrophic.
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

    /* --- The primitive both halves share ------------------------------- */

    /**
     * AES-256-GCM with the context as additional authenticated data.
     *
     * GCM rather than CBC: it authenticates as well as encrypts, so a modified
     * ciphertext fails loudly instead of decrypting to garbage that some later
     * line of code then trusts.
     *
     * @param  array<string, string>  $context
     */
    public static function seal(string $plaintext, string $key, array $context): string
    {
        // 12 bytes is the GCM standard IV length, and it is random per message.
        // Reusing an IV with the same key breaks GCM completely — not "weakens",
        // breaks — which is why one is generated here rather than passed in.
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

        // Everything needed to decrypt except the key, in one opaque string.
        return base64_encode($iv.$tag.$ciphertext);
    }

    /**
     * @param  array<string, string>  $context
     */
    public static function open(string $sealed, string $key, array $context): string
    {
        $raw = base64_decode($sealed, true);

        if ($raw === false || strlen($raw) < 29) {
            // 12 IV + 16 tag + at least 1 byte of ciphertext.
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
            // One message for a wrong key, a wrong context and a tampered
            // ciphertext. Distinguishing them hands an attacker an oracle.
            throw new VaultException('Could not decrypt.');
        }

        return $plaintext;
    }

    /**
     * A stable string for the context, so the same logical context always
     * produces the same AAD regardless of key order.
     *
     * @param  array<string, string>  $context
     */
    private static function canonical(array $context): string
    {
        ksort($context);

        return json_encode($context, JSON_THROW_ON_ERROR);
    }
}
