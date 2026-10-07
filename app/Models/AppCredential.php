<?php

namespace App\Models;

use App\Models\Concerns\HasPrefixedUlid;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A customer's shop keys, sealed.
 *
 * `$guarded = ['*']` and no fillable list: the only thing allowed to write this
 * row is `CredentialVault`, which uses `forceFill`. A mass-assignable
 * credential model is one careless `->update($request->all())` away from an
 * unencrypted secret in the ciphertext column.
 *
 * There is deliberately **no accessor that decrypts**. Reading a secret goes
 * through `CredentialVault::reveal()`, which audits. An Eloquent cast would be
 * more convenient and would make every read invisible.
 */
#[Hidden(['ciphertext', 'wrapped_key'])]
class AppCredential extends Model
{
    use HasPrefixedUlid;

    protected $guarded = ['*'];

    public static function idPrefix(): string
    {
        return 'crd';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['last_used_at' => 'datetime'];
    }

    /**
     * Belt and braces over `$hidden`: this object should not be able to render
     * key material even through a `dd()` or an exception context.
     */
    public function __debugInfo(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'ciphertext' => '[redacted]',
            'wrapped_key' => '[redacted]',
        ];
    }

    /** @return BelongsTo<App, $this> */
    public function app(): BelongsTo
    {
        return $this->belongsTo(App::class);
    }
}
