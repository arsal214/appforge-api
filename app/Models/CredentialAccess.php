<?php

namespace App\Models;

use App\Models\Concerns\HasPrefixedUlid;
use Illuminate\Database\Eloquent\Model;

/**
 * Append-only record of who touched which credential, and why.
 *
 * Same rules as the admin audit log, for the same reason: a trail that can be
 * edited is not a trail. No `updated_at`, mass assignment closed, and hooks that
 * refuse changes outright.
 */
class CredentialAccess extends Model
{
    use HasPrefixedUlid;

    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    public static function idPrefix(): string
    {
        return 'cac';
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new \RuntimeException('Credential access records cannot be modified.');
        });

        static::deleting(function (): void {
            throw new \RuntimeException('Credential access records cannot be deleted.');
        });
    }
}
