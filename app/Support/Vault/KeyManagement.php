<?php

namespace App\Support\Vault;

interface KeyManagement
{
    public function generateDataKey(array $context): DataKey;

    public function unwrapDataKey(string $wrapped, array $context): string;

    public function id(): string;

    public function describe(): string;

    public function isProductionGrade(): bool;
}
