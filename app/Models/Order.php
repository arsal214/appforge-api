<?php

namespace App\Models;

use App\Models\Concerns\HasPrefixedUlid;
use AppForge\Schema\Contract;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['app_id', 'number', 'status', 'kind', 'form_id', 'is_test', 'lines', 'details', 'total', 'platform'])]
class Order extends Model
{
    use HasPrefixedUlid;

    public static function statuses(): array
    {
        return Contract::vocabulary('orderStatuses');
    }

    public static function kinds(): array
    {
        return Contract::vocabulary('submitDestinations');
    }

    public static function statusesFor(string $kind): array
    {
        return Contract::ORDER_WORKFLOW[$kind] ?? Contract::ORDER_WORKFLOW['order'];
    }

    public static function idPrefix(): string
    {
        return 'ord';
    }

    protected function casts(): array
    {
        return [
            'is_test' => 'boolean',
            'lines' => 'array',
            'details' => 'array',
        ];
    }

    public function app(): BelongsTo
    {
        return $this->belongsTo(App::class);
    }
}
