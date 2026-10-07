<?php

namespace App\Models;

use App\Models\Concerns\HasPrefixedUlid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The editor's working draft. One row per app, overwritten on every save.
 *
 * No OrganizationScope here: this table has no `organization_id`, and adding
 * one would create a second source of truth for who owns what. Tenancy is
 * enforced by only ever reaching a config through an app the caller already
 * proved they own.
 */
#[Fillable(['app_id', 'schema_version', 'config', 'updated_by'])]
class AppConfig extends Model
{
    use HasFactory, HasPrefixedUlid;

    public static function idPrefix(): string
    {
        return 'cfg';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'config' => 'array',
        ];
    }

    /** @return BelongsTo<App, $this> */
    public function app(): BelongsTo
    {
        return $this->belongsTo(App::class);
    }
}
