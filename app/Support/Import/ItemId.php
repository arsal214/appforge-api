<?php

namespace App\Support\Import;

final class ItemId
{
    // Derived, not random: the panel keeps a hand-picked grid by item id,
    // so random ids would empty it on every import.
    public static function for(string $provider, string $sourceId): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_-]/', '', $sourceId) ?? '';

        if ($safe === '') {
            $safe = substr(hash('sha256', $sourceId), 0, 16);
        }

        return substr("itm_{$provider}_{$safe}", 0, 64);
    }
}
