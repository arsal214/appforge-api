<?php

namespace App\Support\Import;

final class CatalogueWriter
{
    private const CATALOGUE_TYPES = ['product_list', 'product_grid'];

    public static function write(array $config, array $items): array
    {
        $targetId = self::targetId($config);

        if ($targetId === null) {
            throw ImportFailed::because(
                'nowhere_to_put_them',
                'This app has no menu or product list to fill. Add one in the builder first, then import.',
            );
        }

        $keep = [];

        foreach ($items as $item) {
            if (is_string($item['id'] ?? null)) {
                $keep[$item['id']] = $item;
            }
        }

        $screens = $config['screens'] ?? [];

        foreach ($screens as $screenIndex => $screen) {
            foreach ($screen['sections'] ?? [] as $sectionIndex => $section) {
                if (! in_array($section['type'] ?? null, self::CATALOGUE_TYPES, true)) {
                    continue;
                }

                if (($section['props']['source']['kind'] ?? null) !== 'literal'
                    && ($section['id'] ?? null) !== $targetId) {
                    continue;
                }

                $next = ($section['id'] ?? null) === $targetId
                    ? $items
                    : self::surviving($section['props']['source']['items'] ?? [], $keep);

                $screens[$screenIndex]['sections'][$sectionIndex]['props']['source'] = [
                    'kind' => 'literal',
                    'items' => $next,
                ];
            }
        }

        $config['screens'] = $screens;

        return $config;
    }

    public static function targetId(array $config): ?string
    {
        $grid = null;

        foreach ($config['screens'] ?? [] as $screen) {
            foreach ($screen['sections'] ?? [] as $section) {
                $id = $section['id'] ?? null;

                if (! is_string($id)) {
                    continue;
                }

                if (($section['type'] ?? null) === 'product_list') {
                    return $id;
                }

                if (($section['type'] ?? null) === 'product_grid') {
                    $grid ??= $id;
                }
            }
        }

        return $grid;
    }

    private static function surviving(array $existing, array $keep): array
    {
        $kept = [];

        foreach ($existing as $item) {
            $id = $item['id'] ?? null;

            if (is_string($id) && isset($keep[$id])) {
                $kept[] = $keep[$id];
            }
        }

        return $kept;
    }
}
