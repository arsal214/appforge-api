<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

final class MediaReferences
{
    public static function using(string $needle, string $organizationId): array
    {
        $apps = DB::table('apps')
            ->where('organization_id', $organizationId)
            ->get(['id', 'name', 'deleted_at'])
            ->mapWithKeys(fn (object $app): array => [
                $app->id => $app->deleted_at === null
                    ? (string) $app->name
                    : $app->name.' (in the Bin)',
            ]);

        if ($apps->isEmpty()) {
            return self::profiles($organizationId, $needle);
        }

        $appIds = $apps->keys()->all();

        $inDrafts = DB::table('app_configs')
            ->whereIn('app_id', $appIds)
            ->where('config', 'like', '%'.self::escape($needle).'%')
            ->pluck('app_id');

        $inVersions = DB::table('app_versions')
            ->whereIn('app_id', $appIds)
            ->where('config', 'like', '%'.self::escape($needle).'%')
            ->distinct()
            ->pluck('app_id');

        $names = $inDrafts->merge($inVersions)
            ->unique()
            ->map(fn (string $appId): string => (string) $apps[$appId])
            ->values()
            ->all();

        sort($names);

        return [...$names, ...self::profiles($organizationId, $needle)];
    }

    private static function profiles(string $organizationId, string $needle): array
    {
        $categories = DB::table('business_profiles')
            ->where('organization_id', $organizationId)
            ->where('data', 'like', '%'.self::escape($needle).'%')
            ->pluck('category')
            ->map(fn (string $category): string => "your {$category} business details")
            ->all();

        return array_values($categories);
    }

    private static function escape(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $value);
    }
}
