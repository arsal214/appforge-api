<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The id a store knows an app by: `com.samspizza.app`.
 *
 * Both stores bind an app to it for good and neither will take the same one
 * twice, so every app needs its own. The shape is the one both accept —
 * Android forbids hyphens, Apple forbids underscores, so neither is allowed.
 */
final class BundleId
{
    public const PATTERN = '/^[a-z][a-z0-9]*(\.[a-z][a-z0-9]*)+$/';

    public const MAX_LENGTH = 155;

    /** Ours, and the platforms' own: an app claiming one is refused at the store. */
    private const RESERVED = ['com.appforge.shell', 'com.apple.', 'com.google.', 'com.android.'];

    public static function valid(string $id): bool
    {
        return self::wellFormed($id) && ! self::reserved($id);
    }

    public static function wellFormed(string $id): bool
    {
        return strlen($id) <= self::MAX_LENGTH && preg_match(self::PATTERN, $id) === 1;
    }

    public static function reserved(string $id): bool
    {
        foreach (self::RESERVED as $reserved) {
            if ($id === rtrim($reserved, '.') || str_starts_with($id, $reserved)) {
                return true;
            }
        }

        return false;
    }

    /**
     * An id nobody has, made from the app's name. Looked for across every
     * business, not just this one: the stores do not care whose app it is.
     */
    public static function suggest(string $name): string
    {
        $word = preg_replace('/[^a-z0-9]/', '', Str::lower(Str::ascii($name))) ?? '';
        $word = substr($word, 0, 40);

        // A part cannot start with a digit, and a name in another script can
        // come out empty.
        if ($word === '' || ctype_digit($word[0])) {
            $word = 'app'.$word;
        }

        $candidate = "com.{$word}.app";

        for ($n = 2; self::taken($candidate) || ! self::valid($candidate); $n++) {
            $candidate = "com.{$word}{$n}.app";
        }

        return $candidate;
    }

    private static function taken(string $id): bool
    {
        return DB::table('apps')->where('bundle_id', $id)->exists();
    }
}
