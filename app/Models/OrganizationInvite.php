<?php

namespace App\Models;

use App\Models\Concerns\HasPrefixedUlid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['email', 'role', 'token_hash', 'invited_by', 'expires_at', 'accepted_at'])]
class OrganizationInvite extends Model
{
    use HasPrefixedUlid;

    public const TTL_DAYS = 14;

    public static function idPrefix(): string
    {
        return 'inv';
    }

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public function isOpen(): bool
    {
        return $this->accepted_at === null && $this->expires_at->isFuture();
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
