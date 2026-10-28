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
            'test' => $this->is_test,
            'lines' => $this->lines,
            'details' => $this->details,
            'total' => $this->total,
            'platform' => $this->platform,
            'createdAt' => Iso8601::from($this->created_at),
            'updatedAt' => Iso8601::from($this->updated_at),
        ];
    }
}
