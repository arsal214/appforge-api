<?php

namespace App\Support\Vault;

/**
 * What kinds of secret the vault holds, and which fields each one needs.
 *
 * A closed vocabulary, like everything else in this system. It also records
 * *where* each secret may legitimately be decrypted, which is the difference
 * between a vault and a shared password file:
 *
 *  - `data`  — a shop's API keys. Decrypted by the proxy, per request, to fetch
 *              a catalogue on a customer's behalf.
 *  - `build` — signing and delivery keys. Decrypted only inside an ephemeral
 *              build worker that is destroyed afterwards.
 *
 * Neither is ever decrypted by the web process serving the panel. That
 * separation is not enforced by deployment yet — everything currently runs in
 * one Laravel app — so `purpose` is recorded on every access instead, and the
 * README says plainly that the split is required before real customer
 * credentials are accepted.
 */
final class CredentialType
{
    /** @var array<string, array{label: string, fields: list<string>, secretField: string, use: string}> */
    public const ALL = [
        'woocommerce' => [
            'label' => 'WooCommerce',
            'fields' => ['storeUrl', 'consumerKey', 'consumerSecret'],
            'secretField' => 'consumerSecret',
            'use' => 'data',
        ],
        'shopify' => [
            'label' => 'Shopify',
            'fields' => ['shopDomain', 'accessToken'],
            'secretField' => 'accessToken',
            'use' => 'data',
        ],
        'apns' => [
            'label' => 'Apple push (APNs)',
            'fields' => ['keyId', 'teamId', 'privateKey'],
            'secretField' => 'privateKey',
            'use' => 'build',
        ],
        'fcm' => [
            'label' => 'Google push (FCM)',
            'fields' => ['serviceAccountJson'],
            'secretField' => 'serviceAccountJson',
            'use' => 'build',
        ],
    ];

    /** @return list<string> */
    public static function names(): array
    {
        return array_keys(self::ALL);
    }

    public static function exists(string $type): bool
    {
        return array_key_exists($type, self::ALL);
    }

    /** @return list<string> */
    public static function fields(string $type): array
    {
        return self::ALL[$type]['fields']
            ?? throw new VaultException("Unknown credential type [{$type}].");
    }

    /**
     * The last four characters of the field a human would recognise.
     *
     * Four, not eight: enough to answer "is this the key I pasted?", not enough
     * to help anyone brute-force the rest.
     *
     * @param  array<string, string>  $values
     */
    public static function hintFor(string $type, array $values): ?string
    {
        $field = self::ALL[$type]['secretField'] ?? null;
        $value = $field ? ($values[$field] ?? null) : null;

        if (! is_string($value) || strlen($value) < 4) {
            return null;
        }

        return '…'.substr($value, -4);
    }
}
