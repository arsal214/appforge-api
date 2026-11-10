<?php

namespace App\Support\Import;

use AppForge\Schema\Contract;

final class Text
{
    private const SUBTITLE_LENGTH = 120;

    public static function shorten(string $value, ?int $limit = null): string
    {
        $limit ??= Contract::limit('maxShortText');
        $value = trim($value);

        if (mb_strlen($value) <= $limit) {
            return $value;
        }

        return rtrim(mb_substr($value, 0, $limit - 1)).'…';
    }

    public static function fromHtml(string $html): ?string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $text = trim((string) preg_replace('/\s+/u', ' ', str_replace("\u{a0}", ' ', $text)));

        return $text === '' ? null : self::shorten($text, self::SUBTITLE_LENGTH);
    }
}
