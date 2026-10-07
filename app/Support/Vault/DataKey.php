<?php

namespace App\Support\Vault;

/**
 * A freshly minted data key.
 *
 * `plaintext` is live key material. It exists for the duration of one encrypt
 * or decrypt and is then dropped — never stored, never logged, never returned
 * from anything reachable over HTTP.
 */
final class DataKey
{
    public function __construct(
        public readonly string $plaintext,
        public readonly string $wrapped,
    ) {}

    /**
     * Stops the key material appearing in a stack trace, a `dd()`, or a queued
     * job payload. PHP will happily print private properties in a var_dump of an
     * exception context, and that is exactly where secrets leak from.
     */
    public function __debugInfo(): array
    {
        return ['plaintext' => '[redacted]', 'wrapped' => '[redacted]'];
    }

    public function __toString(): string
    {
        return '[DataKey]';
    }
}
