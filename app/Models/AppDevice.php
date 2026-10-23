<?php

namespace App\Models;

use App\Models\Concerns\HasPrefixedUlid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['app_id', 'token', 'token_hash', 'platform', 'locale', 'shell_version', 'last_seen_at'])]
#[Hidden(['token', 'token_hash'])]
class AppDevice extends Model
{
    use HasPrefixedUlid;

    public static function idPrefix(): string
    {
        return 'dev';
    }

    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime'];
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public function app(): BelongsTo
    {
        return $this->belongsTo(App::class);
    }
}
