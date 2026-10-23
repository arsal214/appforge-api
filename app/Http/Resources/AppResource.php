<?php

namespace App\Http\Resources;

use App\Models\App;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin App
 */
class AppResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'organizationId' => $this->organization_id,
            'name' => $this->name,
            'slug' => $this->slug,
            'sourceUrl' => $this->source_url,
            'templateId' => $this->template_id,
            'industry' => $this->industry,
            'category' => $this->category,
            'status' => $this->status,
            'accentColor' => $this->accent_color,
            'publishedVersion' => $this->published_version,
            'createdAt' => Iso8601::from($this->created_at),
            'updatedAt' => Iso8601::from($this->updated_at),
        ];
    }
}
