<?php

namespace App\Models;

use App\Models\Concerns\HasPrefixedUlid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Someone who uses a customer's app — the shop's customer, not ours.
 *
 * Deliberately not `User` and not on Sanctum: a panel route trusts any Sanctum
 * token, so a token issued here must be one the panel has never heard of.
 */
#[Fillable(['app_id', 'name', 'email', 'password'])]
#[Hidden(['password'])]
class AppUser extends Model
{
    use HasPrefixedUlid;

    public static function idPrefix(): string
    {
        return 'cus';
    }

    protected function casts(): array
    {
        return ['password' => 'hashed'];
    }

    public function app(): BelongsTo
    {
        return $this->belongsTo(App::class);
    }

    /** @return array{id: string, name: string, email: string} */
    public function profile(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
        ];
    }
}
