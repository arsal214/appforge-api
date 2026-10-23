<?php

namespace App\Support\Vault;

final class DataKey
{
    public function __construct(
        public readonly string $plaintext,
        public readonly string $wrapped,
    ) {}

    public function __debugInfo(): array
    {
        return ['plaintext' => '[redacted]', 'wrapped' => '[redacted]'];
    }

    public function __toString(): string
    {
        return '[DataKey]';
    }
}
