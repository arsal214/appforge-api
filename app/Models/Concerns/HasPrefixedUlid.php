<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Support\Str;

/**
 * ULID primary keys with a three-letter type prefix: `app_01jz...`.
 *
 * The prefix costs four characters and buys two things. An id in a log line, a
 * support ticket or a Stripe metadata field says what it points at without a
 * lookup, and an id pasted into the wrong endpoint is rejected by shape rather
 * than by a database round trip that might have matched something.
 *
 * Prefixes must be exactly three characters: 3 + 1 + 26 = the char(30) columns
 * the migrations declare.
 */
trait HasPrefixedUlid
{
    use HasUlids;

    abstract public static function idPrefix(): string;

    public function newUniqueId(): string
    {
        return static::idPrefix().'_'.strtolower((string) Str::ulid());
    }

    /**
     * Used by route-model binding to reject a malformed id before it reaches
     * the database. A wrong-shaped id is a 404, same as someone else's app.
     */
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
