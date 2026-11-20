<?php

namespace App\Http\Resources;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Order
 */
class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'appId' => $this->app_id,
            'number' => $this->number,
            'status' => $this->status,
            'kind' => $this->kind,
            'formId' => $this->form_id,
            'test' => $this->is_test,
            'lines' => $this->lines,
            'details' => $this->details,
            'total' => $this->total,
            'tax' => $this->tax,
            'handover' => $this->handover,
            'platform' => $this->platform,
            'customer' => $this->appUser === null ? null : [
                'name' => $this->appUser->name,
                'email' => $this->appUser->email,
            ],
            'createdAt' => Iso8601::from($this->created_at),
            'updatedAt' => Iso8601::from($this->updated_at),
        ];
    }
}
