<?php

namespace App\Models;

use App\Models\Concerns\HasPrefixedUlid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One push notification, from draft to sent.
 *
 * A sent campaign is a permanent record of what a business said to its own
 * customers. It is never edited after sending and never deleted from the panel —
 * the first time someone complains about a notification, "what exactly did we
 * send, when, and to how many people" has to be answerable.
 */
#[Fillable([
    'app_id', 'title', 'body', 'action', 'status',
    'scheduled_for', 'sent_at', 'recipient_count',
    'failure_count', 'failure_reason', 'created_by',
])]
class PushCampaign extends Model
{
    use HasPrefixedUlid;

    /**
     * `sending` exists so a crashed worker leaves evidence. Without it a job
     * that died mid-send is indistinguishable from one that never started, and
     * the retry sends the whole thing twice.
     */
    public const STATUSES = ['draft', 'scheduled', 'sending', 'sent', 'failed'];

    public static function idPrefix(): string
    {
        return 'psh';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'action' => 'array',
            'scheduled_for' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    /** Sent and failed campaigns are history; nothing may change them. */
    public function isEditable(): bool
    {
        return in_array($this->status, ['draft', 'scheduled'], true);
    }

    /** @return BelongsTo<App, $this> */
    public function app(): BelongsTo
    {
        return $this->belongsTo(App::class);
    }
}
