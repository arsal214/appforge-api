<?php

namespace App\Models;

use App\Models\Concerns\HasPrefixedUlid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The tenant. One organisation per customer business; every user and every app
 * hangs off exactly one.
 */
#[Fillable(['name', 'slug'])]
class Organization extends Model
{
    use HasFactory, HasPrefixedUlid;

    public static function idPrefix(): string
    {
        return 'org';
    }

    /** @return HasMany<User, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** @return HasMany<App, $this> */
    public function apps(): HasMany
    {
        return $this->hasMany(App::class);
    }
}
