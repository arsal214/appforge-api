<?php

namespace App\Models;

use App\Models\Concerns\HasPrefixedUlid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'slug', 'closing_at', 'closing_by'])]
class Organization extends Model
{
    use HasFactory, HasPrefixedUlid;

    /**
     * How long a closed account can still be taken back.
     *
     * Everything keeps working during the wind-down — the apps stay served and
     * the panel stays open. A grace period that takes the live app down on day
     * one would have done most of the damage it exists to prevent.
     */
    public const GRACE_DAYS = 30;

    public static function idPrefix(): string
    {
        return 'org';
    }

    protected function casts(): array
    {
        return [
            'closing_at' => 'datetime',
        ];
    }

    public function isClosing(): bool
    {
        return $this->closing_at !== null;
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function apps(): HasMany
    {
        return $this->hasMany(App::class);
    }

    public function invites(): HasMany
    {
        return $this->hasMany(OrganizationInvite::class);
    }

    public function businessProfiles(): HasMany
    {
        return $this->hasMany(BusinessProfile::class);
    }
}
