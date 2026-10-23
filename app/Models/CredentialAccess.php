<?php

namespace App\Models;

use App\Models\Concerns\HasPrefixedUlid;
use Illuminate\Database\Eloquent\Model;

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
