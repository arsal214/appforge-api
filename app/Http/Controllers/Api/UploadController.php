<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UploadResource;
use App\Models\Upload;
use App\Support\MediaReferences;
use App\Support\MediaUrl;
use AppForge\Schema\Contract;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UploadController extends Controller
{
    private const PER_PAGE = 60;

    private const EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'before' => ['nullable', 'string', 'max:30'],
        ]);

        $query = Upload::query()->orderByDesc('id');

        if (($validated['before'] ?? null) !== null) {
            $query->where('id', '<', $validated['before']);
        }

        $rows = $query->limit(self::PER_PAGE + 1)->get();

        return response()->json([
            'data' => [
                'uploads' => UploadResource::collection($rows->take(self::PER_PAGE)),
                'hasMore' => $rows->count() > self::PER_PAGE,
            ],
        ]);
    }

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

        $upload = Upload::create([
            'organization_id' => $organizationId,
            'path' => $path,
            'name' => Str::limit($file->getClientOriginalName() ?: basename($path), 120, ''),
            'mime' => $mime,
            'bytes' => $file->getSize(),
        ]);

        return response()->json([
            'data' => [
                'url' => MediaUrl::absolute($path),
                'upload' => new UploadResource($upload),
            ],
        ], 201);
    }

    public function destroy(Upload $upload): JsonResponse
    {
        $inUse = MediaReferences::using($upload->reference(), $upload->organization_id);

        if ($inUse !== []) {
            return response()->json([
                'message' => 'That image is still being used by '
                    .$this->readable($inUse)
                    .'. Remove it there first, then delete it here.',
            ], 409);
        }

        $path = $upload->path;
        $upload->delete();

        Storage::disk('public')->delete($path);

        return response()->json(['data' => null], 200);
    }

    private function readable(array $names): string
    {
        if (count($names) === 1) {
            return $names[0];
        }

        $last = array_pop($names);

        return implode(', ', $names).' and '.$last;
    }
}
