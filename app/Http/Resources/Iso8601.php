<?php

namespace App\Http\Resources;

use Carbon\CarbonInterface;

final class Iso8601
{
    public static function from(?CarbonInterface $date): ?string
    {
        return $date?->utc()->toIso8601ZuluString('millisecond');
    }
}
