<?php

namespace App\Models;

use App\Models\Concerns\HasPrefixedUlid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['app_id', 'version', 'schema_version', 'config', 'notes', 'created_by'])]
class AppVersion extends Model
{
    use HasFactory, HasPrefixedUlid;

    public const UPDATED_AT = null;

    public static function idPrefix(): string
    {
        return 'ver';
    }

    protected function casts(): array
    {
        return [
            'config' => 'array',
            'version' => 'integer',
        ];
    }

    public function app(): BelongsTo
    {
        return $this->belongsTo(App::class);
    }
}
