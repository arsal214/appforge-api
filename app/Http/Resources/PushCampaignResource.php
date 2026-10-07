<?php

namespace App\Http\Resources;

use App\Models\PushCampaign;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PushCampaign */
class PushCampaignResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'appId' => $this->app_id,
            'title' => $this->title,
            'body' => $this->body,
            'action' => $this->action,
            'status' => $this->status,
            'scheduledFor' => Iso8601::from($this->scheduled_for),
            'sentAt' => Iso8601::from($this->sent_at),
            'recipientCount' => $this->recipient_count,
            'failureCount' => $this->failure_count,
            'failureReason' => $this->failure_reason,
            'createdAt' => Iso8601::from($this->created_at),
        ];
    }
}
