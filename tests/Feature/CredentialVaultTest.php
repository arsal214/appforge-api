<?php

namespace Tests\Feature;

use App\Models\App;
use App\Models\CredentialAccess;
use App\Models\Organization;
use App\Support\Vault\CredentialVault;
use App\Support\Vault\LocalKeyManagement;
use App\Support\Vault\VaultException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The vault holds the keys to other people's businesses. These tests are the
 * closest thing this repository has to a safety case for that.
 */
class CredentialVaultTest extends TestCase
{
    use RefreshDatabase;

    private function vault(): CredentialVault
    {
        return app(CredentialVault::class);
    }

    private function app(): App
    {
        return App::factory()->for(Organization::factory())->create();
    }

    /** @return array<string, string> */
    private function wooValues(): array
    {
        return [
            'storeUrl' => 'https://shop.example',
            'consumerKey' => 'ck_live_abcdefghijklmnop',
            'consumerSecret' => 'cs_live_zyxwvutsrq123456',
        ];
    }

    /* --- The round trip -------------------------------------------------- */

    #[Test]
    public function a_credential_round_trips(): void
    {
        $app = $this->app();
        $credential = $this->vault()->put($app, 'woocommerce', $this->wooValues(), null);

        $this->assertSame(
            $this->wooValues(),
            $this->vault()->reveal($credential, 'test: round trip'),
        );
    }

    #[Test]
    public function the_secret_is_never_stored_in_plaintext(): void
    {
        $app = $this->app();
        $this->vault()->put($app, 'woocommerce', $this->wooValues(), null);

        // Read the raw row, not the model. Anything readable here is readable
        // by anyone who gets a database dump.
        $row = (array) \DB::table('app_credentials')->first();
        $raw = json_encode($row);

        $this->assertStringNotContainsString('cs_live_zyxwvutsrq123456', (string) $raw);
        $this->assertStringNotContainsString('ck_live_abcdefghijklmnop', (string) $raw);
        $this->assertStringNotContainsString('shop.example', (string) $raw);
    }

    #[Test]
    public function only_a_four_character_hint_is_kept_in_the_clear(): void
    {
        $app = $this->app();
        $credential = $this->vault()->put($app, 'woocommerce', $this->wooValues(), null);

        // Enough to answer "is this the key I pasted?", not enough to help
        // anyone reconstruct the rest.
        $this->assertSame('…3456', $credential->hint);
    }

    /* --- Context binding: the substitution attack ------------------------ */

    #[Test]
    public function a_ciphertext_moved_to_another_credential_will_not_decrypt(): void
    {
        // The attack encryption alone does not stop: lift a ciphertext from one
        // row and paste it into another you control. The authenticated context
        // binds each ciphertext to its own tenant, app, credential and type.
        $victim = $this->app();
        $attacker = $this->app();

        $stolen = $this->vault()->put($victim, 'woocommerce', $this->wooValues(), null);
        $mine = $this->vault()->put($attacker, 'woocommerce', [
            'storeUrl' => 'https://mine.example',
            'consumerKey' => 'ck_mine',
            'consumerSecret' => 'cs_mine',
        ], null);

        $mine->forceFill([
            'ciphertext' => $stolen->ciphertext,
            'wrapped_key' => $stolen->wrapped_key,
        ])->save();

        $this->expectException(VaultException::class);
        $this->vault()->reveal($mine->fresh(), 'test: substitution');
    }

    #[Test]
    public function a_tampered_ciphertext_is_rejected_rather_than_returning_garbage(): void
    {
        $app = $this->app();
        $credential = $this->vault()->put($app, 'woocommerce', $this->wooValues(), null);

        // AES-GCM authenticates. A flipped bit must fail loudly, not decrypt to
        // nonsense that some later line of code then trusts.
        $raw = base64_decode($credential->ciphertext, true);
        $raw[40] = $raw[40] === 'a' ? 'b' : 'a';
        $credential->forceFill(['ciphertext' => base64_encode($raw)])->save();

        $this->expectException(VaultException::class);
        $this->vault()->reveal($credential->fresh(), 'test: tamper');
    }

    #[Test]
    public function every_write_uses_a_fresh_data_key(): void
    {
        // Rotating a credential must rotate the key protecting it, or the old
        // secret and its replacement sit under the same key.
        $app = $this->app();

        $first = $this->vault()->put($app, 'woocommerce', $this->wooValues(), null);
        $firstKey = $first->wrapped_key;

        $second = $this->vault()->put($app, 'woocommerce', $this->wooValues(), null);

        $this->assertNotSame($firstKey, $second->fresh()->wrapped_key);
    }

    #[Test]
    public function the_same_secret_encrypts_differently_every_time(): void
    {
        // A deterministic ciphertext would leak that two customers use the same
        // key, and would mean a reused GCM nonce — which breaks GCM outright.
        $one = $this->vault()->put($this->app(), 'woocommerce', $this->wooValues(), null);
        $two = $this->vault()->put($this->app(), 'woocommerce', $this->wooValues(), null);

        $this->assertNotSame($one->ciphertext, $two->ciphertext);
    }

    /* --- Auditing -------------------------------------------------------- */

    #[Test]
    public function every_reveal_is_recorded_with_its_purpose(): void
    {
        $app = $this->app();
        $credential = $this->vault()->put($app, 'woocommerce', $this->wooValues(), null);

        $this->vault()->reveal($credential, 'proxy: catalogue fetch', 'proxy');

        $this->assertDatabaseHas('credential_accesses', [
            'credential_id' => $credential->id,
            'action' => 'revealed',
            'actor_type' => 'proxy',
            'purpose' => 'proxy: catalogue fetch',
        ]);
    }

    #[Test]
    public function a_failed_reveal_is_recorded_too(): void
    {
        // The more interesting of the two records: this is what an attack looks
        // like, and an investigation starts from it.
        $app = $this->app();
        $credential = $this->vault()->put($app, 'woocommerce', $this->wooValues(), null);
        $credential->forceFill(['wrapped_key' => base64_encode(random_bytes(60))])->save();

        try {
            $this->vault()->reveal($credential->fresh(), 'test: broken key');
        } catch (VaultException) {
            // expected
        }

        $this->assertDatabaseHas('credential_accesses', [
            'credential_id' => $credential->id,
            'action' => 'reveal_failed',
        ]);
    }

    #[Test]
    public function a_reveal_without_a_stated_purpose_is_refused(): void
    {
        // "Why was this secret read?" has to be answerable six months later.
        $credential = $this->vault()->put($this->app(), 'woocommerce', $this->wooValues(), null);

        $this->expectException(VaultException::class);
        $this->vault()->reveal($credential, '   ');
    }

    #[Test]
    public function creating_and_rotating_are_distinguishable_in_the_log(): void
    {
        $app = $this->app();
        $this->vault()->put($app, 'woocommerce', $this->wooValues(), null);
        $this->vault()->put($app, 'woocommerce', $this->wooValues(), null);

        $this->assertDatabaseHas('credential_accesses', ['action' => 'created']);
        $this->assertDatabaseHas('credential_accesses', ['action' => 'rotated']);
    }

    #[Test]
    public function access_records_cannot_be_edited_or_deleted(): void
    {
        $credential = $this->vault()->put($this->app(), 'woocommerce', $this->wooValues(), null);
        $record = CredentialAccess::where('credential_id', $credential->id)->firstOrFail();

        try {
            $record->update(['action' => 'nothing']);
            $this->fail('an access record was modified');
        } catch (\RuntimeException) {
            // expected
        }

        $this->expectException(\RuntimeException::class);
        $record->delete();
    }

    /* --- Destruction ------------------------------------------------------ */

    #[Test]
    public function forgetting_a_credential_destroys_the_key_that_opens_it(): void
    {
        // Crypto-shredding: the wrapped data key goes with the row, so the
        // ciphertext is unrecoverable even from a backup taken a moment ago.
        $app = $this->app();
        $credential = $this->vault()->put($app, 'woocommerce', $this->wooValues(), null);
        $ciphertext = $credential->ciphertext;

        $this->vault()->forget($credential, null);

        $this->assertDatabaseCount('app_credentials', 0);
        $this->assertDatabaseHas('credential_accesses', ['action' => 'deleted']);
        // The audit record survives the thing it describes.
        $this->assertNotEmpty($ciphertext);
    }

    #[Test]
    public function deleting_an_app_takes_its_credentials_with_it(): void
    {
        $app = $this->app();
        $this->vault()->put($app, 'woocommerce', $this->wooValues(), null);

        $app->forceDelete();

        $this->assertDatabaseCount('app_credentials', 0);
        // ...but not the record that they existed.
        $this->assertDatabaseHas('credential_accesses', ['action' => 'created']);
    }

    /* --- Serialisation ---------------------------------------------------- */

    #[Test]
    public function key_material_never_survives_serialisation(): void
    {
        $credential = $this->vault()->put($this->app(), 'woocommerce', $this->wooValues(), null);

        $json = $credential->toJson();
        $this->assertStringNotContainsString('ciphertext', $json);
        $this->assertStringNotContainsString('wrapped_key', $json);

        // `dd()` and exception contexts print debug info, and that is exactly
        // where secrets escape from.
        $debug = print_r($credential->__debugInfo(), true);
        $this->assertStringContainsString('[redacted]', $debug);
    }

    #[Test]
    public function the_encryption_primitive_rejects_a_wrong_key(): void
    {
        $context = ['org' => 'org_1', 'app' => 'app_1'];
        $sealed = LocalKeyManagement::seal('secret', str_repeat('a', 32), $context);

        $this->expectException(VaultException::class);
        LocalKeyManagement::open($sealed, str_repeat('b', 32), $context);
    }

    #[Test]
    public function an_unknown_credential_type_is_refused(): void
    {
        $this->expectException(VaultException::class);
        $this->vault()->put($this->app(), 'my_own_thing', ['x' => 'y'], null);
    }
}
