<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The colour palettes a business made for itself. Saved as one list, the way
 * the builder edits them, and only ever the six colours a theme is made of:
 * a palette is offered in every app, so nothing else rides along in it.
 */
class PaletteController extends Controller
{
    public const MAX = 12;

    public const COLOURS = [
        'accentColor',
        'backgroundColor',
        'surfaceColor',
        'textColor',
        'mutedTextColor',
        'borderColor',
    ];

    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $request->user()->organization->palettes ?? [],
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $hex = ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'];

        $rules = [
            'palettes' => ['present', 'array', 'max:'.self::MAX],
            'palettes.*' => ['array:id,name,'.implode(',', self::COLOURS)],
            'palettes.*.id' => ['required', 'string', 'max:40', 'distinct'],
            'palettes.*.name' => ['required', 'string', 'max:40'],
        ];
        foreach (self::COLOURS as $colour) {
            $rules["palettes.*.{$colour}"] = $hex;
        }

        $palettes = array_map(
            fn (array $palette) => [
                'id' => $palette['id'],
                'name' => trim($palette['name']),
                ...array_map(
                    fn (string $colour) => strtolower($colour),
                    array_intersect_key($palette, array_flip(self::COLOURS)),
                ),
            ],
            $request->validate($rules)['palettes'],
        );

        $organization = $request->user()->organization;
        $organization->update(['palettes' => $palettes === [] ? null : $palettes]);

        return response()->json(['data' => $palettes]);
    }
}
