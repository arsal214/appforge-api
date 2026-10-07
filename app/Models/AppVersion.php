<?php

namespace App\Models;

use App\Models\Concerns\HasPrefixedUlid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A published, immutable snapshot of a config.
 *
 * `UPDATED_AT = null` is not a tidiness choice. A version is what a shell on a
 * customer's phone downloads; if it could be edited in place, "the app my
 * customer is running" would be a moving target and a rollback would have
 * nothing to roll back to. There is no update path, so there is no update
 * timestamp.
 */
#[Fillable(['app_id', 'version', 'schema_version', 'config', 'notes', 'created_by'])]
class AppVersion extends Model
{
    use HasFactory, HasPrefixedUlid;

    public const UPDATED_AT = null;

    public static function idPrefix(): string
    {
        return 'ver';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'config' => 'array',
            'version' => 'integer',
        ];
    }

    /** @return BelongsTo<App, $this> */
    public function app(): BelongsTo
    {
        return $this->belongsTo(App::class);
    }
}
