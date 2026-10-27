<?php

namespace App\Models;

use App\Models\Concerns\HasPrefixedUlid;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'organization_id', 'role'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasPrefixedUlid, Notifiable;

    public const ROLES = ['owner', 'editor', 'staff'];

    /**
     * Two gates, and every role differs on at least one: a role nobody can
     * tell apart from the next one is decoration. Reading apps and working
     * orders is deliberately absent — every member of an organisation can do
     * both, which is what makes 'staff' worth having.
     */
    private const ABILITIES = [
        'owner' => ['team', 'build'],
        'editor' => ['build'],
        'staff' => [],
    ];

    public static function idPrefix(): string
    {
        return 'usr';
    }

    public function allows(string $ability): bool
    {
        return in_array($ability, self::ABILITIES[$this->role] ?? [], true);
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
