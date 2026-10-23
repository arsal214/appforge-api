<?php

namespace App\Models;

use App\Models\Concerns\HasPrefixedUlid;
use App\Models\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'organization_id',
    'name',
    'slug',
    'source_url',
    'template_id',
    'industry',
    'category',
    'status',
    'accent_color',
])]
#[ScopedBy(OrganizationScope::class)]
class App extends Model
{
    use HasFactory, HasPrefixedUlid, SoftDeletes;

    public const STATUSES = [
        'draft',
        'ready',
        'submitted',
        'in_review',
        'live',
        'rejected',
    ];

    public static function idPrefix(): string
    {
        return 'app';
    }

    protected function casts(): array
    {
        return [
            'published_version' => 'integer',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function config(): HasOne
    {
        return $this->hasOne(AppConfig::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(AppVersion::class);
    }

    public function devices(): HasMany
    {
        return $this->hasMany(AppDevice::class);
    }

    public function credentials(): HasMany
    {
        return $this->hasMany(AppCredential::class);
    }

    public function pushCampaigns(): HasMany
    {
        return $this->hasMany(PushCampaign::class);
    }
}
