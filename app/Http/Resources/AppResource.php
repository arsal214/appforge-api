<?php

namespace App\Http\Resources;

use App\Models\App;
use App\Support\MediaUrl;
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
            'bundleId' => $this->bundle_id,
            'sourceUrl' => $this->source_url,
            'templateId' => $this->template_id,
            'industry' => $this->industry,
            'category' => $this->category,
            'status' => $this->status,
            'accentColor' => $this->accent_color,
            'businessProfileId' => $this->business_profile_id,
            'publishedVersion' => $this->published_version,
            'builtVersion' => $this->builtVersion(),
            'logoUrl' => $this->logoUrl(),
            'splashColor' => $this->splash_color,
            'iconUrl' => $this->icon_path === null
                ? null
                : MediaUrl::forDelivery(['icon' => $this->icon_path])['icon'],
            'newOrders' => $this->whenCounted(
                'new_orders',
                fn () => (int) $this->new_orders_count,
            ),
            'newTests' => $this->whenCounted(
                'new_tests',
                fn () => (int) $this->new_tests_count,
            ),
            'createdAt' => Iso8601::from($this->created_at),
            'updatedAt' => Iso8601::from($this->updated_at),
        ];
    }

    /**
     * The newest version frozen with "Build app", whether or not a store has
     * it. Not `published_version`: that one means a store is serving it, and
     * stays empty until something ships a build. Read off the list query
     * when it carried the figure, so a page of apps is not a query each.
     */
    /**
     * The logo saved with the business this app is linked to, so the panel
     * can show the owner's own mark wherever it draws the app's icon. An app
     * linked to nothing, or to details with no logo, has none.
     */
    private function logoUrl(): ?string
    {
        $logo = $this->resource->businessProfile?->data['logoUrl'] ?? null;

        return is_string($logo) && $logo !== ''
            ? MediaUrl::forDelivery(['logoUrl' => $logo])['logoUrl']
            : null;
    }

    private function builtVersion(): ?int
    {
        $attributes = $this->resource->getAttributes();

        $newest = array_key_exists('versions_max_version', $attributes)
            ? $attributes['versions_max_version']
            : $this->resource->versions()->max('version');

        return $newest === null ? null : (int) $newest;
    }
}
