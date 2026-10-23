<?php

namespace App\Http\Resources;

use App\Models\AppVersion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AppVersion
 */
class AppVersionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'appId' => $this->app_id,
            'version' => $this->version,
            'schemaVersion' => $this->schema_version,
            'notes' => $this->notes,
            'createdAt' => Iso8601::from($this->created_at),
        ];
    }
}
