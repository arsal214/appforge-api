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

/**
 * A customer's mobile app.
 *
 * Note the class name shadows the `App` root namespace inside `App\Models`.
 * That is deliberate — the domain word is "app" everywhere else in the estate
 * (the table, the endpoint, the panel's TypeScript type) and inventing a
 * synonym here would cost more than it saves. Use `app()` and `config()`
 * helpers rather than the `App` facade in this namespace.
 */
#[Fillable([
    'organization_id',
    'name',
    'slug',
    'source_url',
    'template_id',
    'industry',
    'status',
    'accent_color',
])]
#[ScopedBy(OrganizationScope::class)]
class App extends Model
{
    use HasFactory, HasPrefixedUlid, SoftDeletes;

    /**
     * The lifecycle from draft to store. `live` is the only one the customer
     * cannot leave on their own — see the delete guard in AppController.
     */
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

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'published_version' => 'integer',
        ];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return HasOne<AppConfig, $this> */
    public function config(): HasOne
    {
        return $this->hasOne(AppConfig::class);
    }

    /** @return HasMany<AppVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(AppVersion::class);
    }

    /** @return HasMany<AppDevice, $this> */
    public function devices(): HasMany
    {
        return $this->hasMany(AppDevice::class);
    }

    /** @return HasMany<AppCredential, $this> */
    public function credentials(): HasMany
    {
        return $this->hasMany(AppCredential::class);
    }

    /** @return HasMany<PushCampaign, $this> */
    public function pushCampaigns(): HasMany
    {
        return $this->hasMany(PushCampaign::class);
    }
}
