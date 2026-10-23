<?php

namespace App\Models;

use App\Models\Concerns\HasPrefixedUlid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'app_id', 'title', 'body', 'action', 'status',
    'scheduled_for', 'sent_at', 'recipient_count',
    'failure_count', 'failure_reason', 'created_by',
])]
class PushCampaign extends Model
{
    use HasPrefixedUlid;

    public const STATUSES = ['draft', 'scheduled', 'sending', 'sent', 'failed'];

    public static function idPrefix(): string
    {
        return 'psh';
    }

    protected function casts(): array
    {
        return [
            'action' => 'array',
            'scheduled_for' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    public function isEditable(): bool
    {
        return in_array($this->status, ['draft', 'scheduled'], true);
    }

    public function app(): BelongsTo
    {
        return $this->belongsTo(App::class);
    }
}
