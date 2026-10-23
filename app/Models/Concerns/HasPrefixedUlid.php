<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Support\Str;

trait HasPrefixedUlid
{
    use HasUlids;

    abstract public static function idPrefix(): string;

    public function newUniqueId(): string
    {
        return static::idPrefix().'_'.strtolower((string) Str::ulid());
    }

    protected function isValidUniqueId($value): bool
    {
        if (! is_string($value)) {
            return false;
        }

        $prefix = static::idPrefix().'_';

        return str_starts_with($value, $prefix)
            && Str::isUlid(substr($value, strlen($prefix)));
    }
}
