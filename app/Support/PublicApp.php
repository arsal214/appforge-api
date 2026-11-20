<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Finds an app for a route anyone can call, where there is no organisation to
 * scope the lookup by.
 *
 * The id must match exactly, not just as the database compares it. MySQL's
 * collation ignores case, accents and trailing spaces, so `APP_01J…`, `àpp_01j…`
 * and `app_01j… ` all find the same row — and each spelling is a different
 * rate-limit key, which turns a limit on guessing a customer's password into
 * no limit at all.
 */
final class PublicApp
{
    public static function exists(string $id): bool
    {
        $found = DB::table('apps')
            ->where('id', $id)
            ->whereNull('deleted_at')
            ->value('id');

        return $found === $id;
    }
}
