<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

final class MediaUrl
{
    private const PREFIX = '/storage/';

    public static function forStorage(array $data): array
    {
        $base = self::base();

        return self::mapStrings(
            $data,
            static fn (string $value): string => str_starts_with($value, $base)
                ? self::PREFIX.substr($value, strlen($base))
                : $value,
        );
    }

    public static function forDelivery(array $data): array
    {
        $base = self::base();

        return self::mapStrings(
            $data,
            static fn (string $value): string => str_starts_with($value, self::PREFIX)
                ? $base.substr($value, strlen(self::PREFIX))
                : $value,
        );
    }

    public static function reference(string $diskPath): string
    {
        return str_starts_with($diskPath, self::PREFIX)
            ? $diskPath
            : self::PREFIX.ltrim($diskPath, '/');
    }

    public static function absolute(string $path): string
    {
        return self::base().ltrim(substr($path, 0, strlen(self::PREFIX)) === self::PREFIX
            ? substr($path, strlen(self::PREFIX))
            : $path, '/');
    }

    private static function base(): string
    {
        $url = Storage::disk('public')->url('');

        if (! str_starts_with($url, 'http://') && ! str_starts_with($url, 'https://')) {
            $url = URL::to($url);
        }

        return rtrim($url, '/').'/';
    }

    private static function mapStrings(array $data, callable $fn): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::mapStrings($value, $fn);
            } elseif (is_string($value)) {
                $data[$key] = $fn($value);
            }
        }

        return $data;
    }
}
