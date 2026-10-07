<?php

namespace App\Models;

use App\Models\Concerns\HasPrefixedUlid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One installed copy of a customer's app.
 *
 * The token is hidden from serialisation and there is no endpoint that returns
 * it. A push token is not a secret in the credential sense, but it is a handle
 * on a specific person's phone, and nothing in the panel needs to see one — the
 * panel needs counts, not identities.
 */
#[Fillable(['app_id', 'token', 'token_hash', 'platform', 'locale', 'shell_version', 'last_seen_at'])]
#[Hidden(['token', 'token_hash'])]
class AppDevice extends Model
{
    use HasPrefixedUlid;

    public static function idPrefix(): string
    {
        return 'dev';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime'];
    }

    /** Deterministic, so re-registering the same device updates rather than duplicates. */
    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /** @return BelongsTo<App, $this> */
    public function app(): BelongsTo
    {
        return $this->belongsTo(App::class);
    }
}
