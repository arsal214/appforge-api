<?php

namespace App\Models;

use App\Models\Concerns\HasPrefixedUlid;
use App\Models\Scopes\OrganizationScope;
use App\Support\MediaUrl;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['organization_id', 'path', 'name', 'mime', 'bytes'])]
#[ScopedBy(OrganizationScope::class)]
class Upload extends Model
{
    use HasFactory, HasPrefixedUlid;

    public static function idPrefix(): string
    {
        return 'upl';
    }

    public function reference(): string
    {
        return MediaUrl::reference($this->path);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
