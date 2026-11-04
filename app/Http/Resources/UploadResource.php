<?php

namespace App\Http\Resources;

use App\Support\MediaUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UploadResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'url' => MediaUrl::absolute($this->path),
            'path' => $this->reference(),
            'name' => $this->name,
            'bytes' => $this->bytes,
            'createdAt' => Iso8601::from($this->created_at),
        ];
    }
}
