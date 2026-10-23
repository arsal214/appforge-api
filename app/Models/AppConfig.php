<?php

namespace App\Models;

use App\Models\Concerns\HasPrefixedUlid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['app_id', 'schema_version', 'config', 'updated_by'])]
class AppConfig extends Model
{
    use HasFactory, HasPrefixedUlid;

    public static function idPrefix(): string
    {
        return 'cfg';
    }

    protected function casts(): array
    {
        return [
            'config' => 'array',
        ];
    }

    public function app(): BelongsTo
    {
        return $this->belongsTo(App::class);
    }
}
