<?php

namespace App\Http\Resources;

use App\Models\OrganizationInvite;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin OrganizationInvite
 */
class OrganizationInviteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'role' => $this->role,
            'expiresAt' => Iso8601::from($this->expires_at),
            'expired' => ! $this->isOpen(),
            'createdAt' => Iso8601::from($this->created_at),
        ];
    }
}
