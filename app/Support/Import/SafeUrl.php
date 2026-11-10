<?php

namespace App\Support\Import;

final class SafeUrl
{
    private static $resolver = null;

    public static function resolveUsing(callable $resolver): void
    {
        self::$resolver = $resolver;
    }

    public static function resolveNormally(): void
    {
        self::$resolver = null;
    }

    // https only: an import carries a consumer secret in a header.
    // Checks every address the host answers with, not just the first.
    // Known gap: DNS rebinding, because the client resolves again - T19.
    public static function check(string $url): string
    {
        $url = trim($url);

        if ($url === '') {
            throw ImportFailed::because('no_store_url', 'No store address was saved with these credentials.');
        }

        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['host'])) {
            throw ImportFailed::because('bad_store_url', 'That store address could not be read as a web address.');
        }

        $scheme = strtolower($parts['scheme'] ?? '');

        if ($scheme !== 'https') {
            throw ImportFailed::because(
                'insecure_store_url',
                'The store address must start with https. Your key would otherwise travel in the open.',
            );
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw ImportFailed::because(
                'credentials_in_url',
                'Remove the username and password from the store address; the key fields hold those.',
            );
        }

        $host = $parts['host'];

        foreach (self::addressesOf($host) as $address) {
            if (self::isPrivate($address)) {
                throw ImportFailed::because(
                    'blocked_host',
                    'That store address points inside a private network, so it was not fetched.',
                );
            }
        }

        return $url;
    }

    private static function addressesOf(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        if (self::$resolver !== null) {
            $given = (self::$resolver)($host);

            if ($given === []) {
                throw ImportFailed::because(
                    'unknown_host',
                    'That store address could not be found. Check it is spelled correctly and is reachable.',
                );
            }

            return $given;
        }

        $records = @dns_get_record($host, DNS_A + DNS_AAAA) ?: [];

        $addresses = [];

        foreach ($records as $record) {
            $address = $record['ip'] ?? $record['ipv6'] ?? null;

            if (is_string($address) && $address !== '') {
                $addresses[] = $address;
            }
        }

        if ($addresses === []) {
            $resolved = gethostbynamel($host);
            $addresses = is_array($resolved) ? $resolved : [];
        }

        if ($addresses === []) {
            throw ImportFailed::because(
                'unknown_host',
                'That store address could not be found. Check it is spelled correctly and is reachable.',
            );
        }

        return $addresses;
    }

    public static function isPrivate(string $address): bool
    {
        $public = filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        );

        if ($public === false) {
            return true;
        }

        if (stripos($address, '::ffff:') === 0) {
            return self::isPrivate(substr($address, 7));
        }

        return false;
    }
}
