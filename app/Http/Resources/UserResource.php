<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An allowlist, not a blocklist. Every field the client sees is named here, so
 * a column added to `users` later is private until someone deliberately adds
 * it. `$hidden` on the model is the other way round and one forgotten entry
 * from a leak.
 *
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'organizationId' => $this->organization_id,
            'createdAt' => Iso8601::from($this->created_at),
        ];
    }
}
