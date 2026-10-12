<?php

namespace App\Models;

use App\Models\Concerns\HasPrefixedUlid;
use App\Models\Scopes\OrganizationScope;
use AppForge\Schema\Contract;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['organization_id', 'category', 'data'])]
#[ScopedBy(OrganizationScope::class)]
class BusinessProfile extends Model
{
    use HasFactory, HasPrefixedUlid;

    public static function idPrefix(): string
    {
        return 'bzp';
    }

    protected function casts(): array
    {
        return [
            'data' => 'array',
        ];
    }

    public function industry(): string
    {
        return Contract::BUSINESS_CATEGORY_INDUSTRY[$this->category]
            ?? throw new \LogicException(
                "Category [{$this->category}] has no industry mapping."
            );
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
