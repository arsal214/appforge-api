<?php

namespace App\Models;

use App\Models\Concerns\HasPrefixedUlid;
use Illuminate\Database\Eloquent\Model;

class SiteEvent extends Model
{
    use HasPrefixedUlid;

    protected $table = 'site_event_daily';

    protected $guarded = ['*'];

    public static function idPrefix(): string
    {
        return 'sev';
    }

    protected function casts(): array
    {
        return [
            'day' => 'date',
            'count' => 'integer',
        ];
    }
}
