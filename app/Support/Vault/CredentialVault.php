<?php

namespace App\Support\Vault;

use App\Models\App as ClientApp;
use App\Models\AppCredential;
use App\Models\CredentialAccess;
use Illuminate\Support\Facades\Request;

/**
 * The only way a credential is written or read.
 *
 * There is no other path. No Eloquent accessor decrypts, no cast unwraps, no
 * helper "just gets the token" — because the moment a second route exists, one
 * of them stops being audited and nobody notices for a year.
 *
 * **Every reveal is recorded, including the ones that fail.** A write is visible
 * in the panel; a read is invisible unless it is written down. A failed reveal
 * is the more interesting record of the two: it is what an attack looks like.
 *
 * The context binds each ciphertext to its tenant, app, credential and type. A
 * ciphertext copied from one row into another decrypts to nothing, which closes
 * the substitution attack that encryption alone leaves open.
 */
class CredentialVault
{
    public function __construct(private readonly KeyManagement $keys) {}

    /**
     * Store or rotate a credential.
     *
     * Rotation overwrites in place. Keeping the previous secret "just in case"
     * would mean a revoked key still sitting in our database, which is the
     * opposite of what revoking one is for.
     *
     * @param  array<string, string>  $values
     */
    public function put(
        ClientApp $app,
        string $type,
        array $values,
        ?string $actorId,
        ?string $label = null,
    ): AppCredential {
        if (! CredentialType::exists($type)) {
            throw new VaultException("Unknown credential type [{$type}].");
        }

        $existing = AppCredential::query()
            ->where('app_id', $app->id)
            ->where('type', $type)
            ->first();

        $credential = $existing ?? new AppCredential;
        $credential->id ??= $credential->newUniqueId();

        $context = $this->context(
            organizationId: $app->organization_id,
            appId: $app->id,
            credentialId: $credential->id,
            type: $type,
        );

        // A fresh data key on every write, so rotating a credential also rotates
        // the key protecting it. Reusing the old one would leave a rotated
        // secret and the secret it replaced under the same key.
        $dataKey = $this->keys->generateDataKey($context);

        $credential->forceFill([
            'app_id' => $app->id,
            'organization_id' => $app->organization_id,
            'type' => $type,
            'ciphertext' => LocalKeyManagement::seal(
                json_encode($values, JSON_THROW_ON_ERROR),
                $dataKey->plaintext,
                $context,
            ),
            'wrapped_key' => $dataKey->wrapped,
            'key_provider' => $this->keys->id(),
            'hint' => CredentialType::hintFor($type, $values),
            'label' => $label,
            'created_by' => $actorId,
        ])->save();

        $this->record(
            $credential,
            $existing ? 'rotated' : 'created',
            'user',
            $actorId,
        );

        return $credential;
    }

    /**
     * Decrypt a credential.
     *
     * `$purpose` is required and free text on purpose: six months from now,
     * reviewing an access log, "which build" or "proxy: catalogue fetch" is the
     * difference between an answer and a shrug.
     *
     * The caller is expected to be a build worker or the proxy. The web process
     * serving the panel has no reason to call this and does not.
     *
     * @return array<string, string>
     */
    public function reveal(
        AppCredential $credential,
        string $purpose,
        string $actorType = 'system',
        ?string $actorId = null,
    ): array {
        if (trim($purpose) === '') {
            throw new VaultException('A reveal must state its purpose.');
        }

        $context = $this->context(
            organizationId: $credential->organization_id,
            appId: $credential->app_id,
            credentialId: $credential->id,
            type: $credential->type,
        );

        try {
            $dataKey = $this->keys->unwrapDataKey($credential->wrapped_key, $context);
            $plaintext = LocalKeyManagement::open(
                $credential->ciphertext,
                $dataKey,
                $context,
            );
        } catch (VaultException $exception) {
            // Recorded before rethrowing. A failed decrypt is what tampering
            // looks like, and it is the entry an investigation starts from.
            $this->record($credential, 'reveal_failed', $actorType, $actorId, $purpose);

            throw $exception;
        }

        $this->record($credential, 'revealed', $actorType, $actorId, $purpose);
        $credential->forceFill(['last_used_at' => now()])->saveQuietly();

        /** @var array<string, string> $values */
        $values = json_decode($plaintext, true, 512, JSON_THROW_ON_ERROR);

        return $values;
    }

    /**
     * Destroy a credential.
     *
     * Deleting the row destroys the wrapped data key with it, which makes the
     * ciphertext unrecoverable even from a backup taken a moment earlier —
     * crypto-shredding, and the only honest way to answer "delete my keys" when
     * backups exist.
     */
    public function forget(AppCredential $credential, ?string $actorId): void
    {
        // Recorded first: after the delete there is no row left to describe.
        $this->record($credential, 'deleted', 'user', $actorId);

        $credential->delete();
    }

    /**
     * @return array<string, string>
     */
    private function context(
        string $organizationId,
        string $appId,
        string $credentialId,
        string $type,
    ): array {
        return [
            'org' => $organizationId,
            'app' => $appId,
            'credential' => $credentialId,
            'type' => $type,
        ];
    }

    private function record(
        AppCredential $credential,
        string $action,
        string $actorType,
        ?string $actorId,
        ?string $purpose = null,
    ): void {
        CredentialAccess::forceCreate([
            'credential_id' => $credential->id,
            'app_id' => $credential->app_id,
            'organization_id' => $credential->organization_id,
            'type' => $credential->type,
            'action' => $action,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'purpose' => $purpose,
            'ip_address' => Request::ip(),
        ]);
    }
}
