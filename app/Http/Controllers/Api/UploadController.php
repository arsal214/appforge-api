<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use AppForge\Schema\Contract;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UploadController extends Controller
{
    private const EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    public function store(Request $request): JsonResponse
    {
        $maxKilobytes = (int) (Contract::limit('maxUploadBytes') / 1024);
        $edge = Contract::limit('maxImageEdge');

        $request->validate(
            [
                'file' => [
                    'required',
                    'file',
                    'image',
                    Rule::dimensions()->maxWidth($edge)->maxHeight($edge),
                    "max:{$maxKilobytes}",
                    'mimetypes:'.implode(',', array_keys(self::EXTENSIONS)),
                ],
            ],
            [
                'file.required' => 'Choose an image to upload.',
                'file.image' => 'That file is not an image.',
                'file.mimetypes' => 'Use a JPG, PNG or WebP image.',
                'file.dimensions' => "Keep the image under {$edge} pixels on each side.",
                'file.max' => 'That image is too large. Keep it under 5 MB.',
            ],
        );

        $file = $request->file('file');
        $mime = (string) $file->getMimeType();

        $extension = self::EXTENSIONS[$mime] ?? throw ValidationException::withMessages([
            'file' => ['Use a JPG, PNG or WebP image.'],
        ]);

        $organizationId = $request->user()->organization_id;

        $path = $file->storeAs(
            "profile-images/{$organizationId}",
            Str::ulid()->toString().'.'.$extension,
            'public',
        );

        if ($path === false) {
            abort(500, 'We could not save that image. Try again in a moment.');
        }

        return response()->json([
            'data' => ['url' => $this->absoluteUrl($path)],
        ], 201);
    }

    private function absoluteUrl(string $path): string
    {
        $url = Storage::disk('public')->url($path);

        return str_starts_with($url, 'http://') || str_starts_with($url, 'https://')
            ? $url
            : URL::to($url);
    }
}
