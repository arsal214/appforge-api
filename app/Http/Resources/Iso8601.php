<?php

namespace App\Http\Resources;

use Carbon\CarbonInterface;

/**
 * One date format for the whole API.
 *
 * The panel validates every timestamp with zod's `z.iso.datetime()`, which
 * wants a `Z`-suffixed ISO 8601 string. Eloquent's default JSON serialisation
 * happens to satisfy it today; relying on that means a framework default change
 * breaks the panel's boundary validation everywhere at once. Formatting
 * explicitly costs one function and removes the coupling.
 */
final class Iso8601
{
    public static function from(?CarbonInterface $date): ?string
    {
        return $date?->utc()->toIso8601ZuluString('millisecond');
    }
}
