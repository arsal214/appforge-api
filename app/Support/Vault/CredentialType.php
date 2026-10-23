<?php

namespace App\Support\Vault;

final class CredentialType
{
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

    public static function names(): array
    {
        return array_keys(self::ALL);
    }

    public static function exists(string $type): bool
    {
        return array_key_exists($type, self::ALL);
    }

    public static function fields(string $type): array
    {
        return self::ALL[$type]['fields']
            ?? throw new VaultException("Unknown credential type [{$type}].");
    }

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
