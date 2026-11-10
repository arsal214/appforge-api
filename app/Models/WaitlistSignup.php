<?php

namespace App\Models;

use App\Models\Concerns\HasPrefixedUlid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['email', 'website_url', 'source'])]
class WaitlistSignup extends Model
{
    use HasPrefixedUlid;

    public static function idPrefix(): string
    {
        return 'wl';
    }
}
