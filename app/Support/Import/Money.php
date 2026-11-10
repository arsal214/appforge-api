<?php

namespace App\Support\Import;

final class Money
{
    private const SYMBOLS = [
        'GBP' => '£',
        'USD' => '$',
        'EUR' => '€',
        'PKR' => 'Rs ',
        'INR' => '₹',
        'AED' => 'AED ',
        'AUD' => 'A$',
        'CAD' => 'C$',
        'JPY' => '¥',
    ];

    public static function format(?string $amount, ?string $currency): ?string
    {
        $amount = is_string($amount) ? trim($amount) : '';

        if ($amount === '' || ! is_numeric($amount)) {
            return null;
        }

        $decimals = $currency === 'JPY' ? 0 : 2;
        $shown = number_format((float) $amount, $decimals, '.', ',');

        $code = strtoupper((string) $currency);

        if ($code === '') {
            return $shown;
        }

        $symbol = self::SYMBOLS[$code] ?? $code.' ';

        return $symbol.$shown;
    }
}
