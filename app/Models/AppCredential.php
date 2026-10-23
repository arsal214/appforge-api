<?php

namespace App\Models;

use App\Models\Concerns\HasPrefixedUlid;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Hidden(['ciphertext', 'wrapped_key'])]
class AppCredential extends Model
{
    use HasPrefixedUlid;

    protected $guarded = ['*'];

    public static function idPrefix(): string
    {
        return 'crd';
    }

    protected function casts(): array
    {
        return ['last_used_at' => 'datetime'];
    }

    public function __debugInfo(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'ciphertext' => '[redacted]',
            'wrapped_key' => '[redacted]',
        ];
    }

    public function app(): BelongsTo
    {
        return $this->belongsTo(App::class);
    }
}
