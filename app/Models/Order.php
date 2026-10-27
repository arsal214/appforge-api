<?php

namespace App\Models;

use App\Models\Concerns\HasPrefixedUlid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['app_id', 'number', 'status', 'lines', 'details', 'total', 'platform'])]
class Order extends Model
{
    use HasPrefixedUlid;

    public const STATUSES = ['new', 'confirmed', 'completed', 'cancelled'];

    public static function idPrefix(): string
    {
        return 'ord';
    }

    protected function casts(): array
    {
        return [
            'lines' => 'array',
            'details' => 'array',
        ];
    }

    public function app(): BelongsTo
    {
        return $this->belongsTo(App::class);
    }
}
