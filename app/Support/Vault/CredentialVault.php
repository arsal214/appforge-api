<?php

namespace App\Support\Vault;

use App\Models\App as ClientApp;
use App\Models\AppCredential;
use App\Models\CredentialAccess;
use Illuminate\Support\Facades\Request;

class CredentialVault
{
    public function __construct(private readonly KeyManagement $keys) {}

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

        $existing = $app->credentials()->where('type', $type)->first();

        $credential = $existing ?? new AppCredential;
        $credential->id ??= $credential->newUniqueId();

        $context = $this->context(
            organizationId: $app->organization_id,
            appId: $app->id,
            credentialId: $credential->id,
            type: $type,
        );

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
            $this->record($credential, 'reveal_failed', $actorType, $actorId, $purpose);

            throw $exception;
        }

        $this->record($credential, 'revealed', $actorType, $actorId, $purpose);
        $credential->forceFill(['last_used_at' => now()])->saveQuietly();

        $values = json_decode($plaintext, true, 512, JSON_THROW_ON_ERROR);

        return $values;
    }

    public function forget(AppCredential $credential, ?string $actorId): void
    {
        $this->record($credential, 'deleted', 'user', $actorId);

        $credential->delete();
    }

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
