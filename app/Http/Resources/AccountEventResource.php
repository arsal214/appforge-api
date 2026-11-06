<?php

namespace App\Http\Resources;

use App\Models\AccountEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AccountEvent
 */
class AccountEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'action' => $this->action,
            'group' => AccountEvent::groupOf($this->action),

            // Built here rather than in the panel so one list of actions
            // decides the wording, and a new action cannot ship as a raw
            // `app.binned` on screen.
            'sentence' => $this->sentence(),

            'subject' => $this->subject,
            'subjectId' => $this->subject_id,
            'context' => $this->context,
            'actor' => [
                'id' => $this->actor_id,
                'name' => $this->actor_name,
                'email' => $this->actor_email,
                'stillOnTheTeam' => $this->actor_id !== null,
            ],
            'at' => Iso8601::from($this->created_at),
        ];
    }
}
