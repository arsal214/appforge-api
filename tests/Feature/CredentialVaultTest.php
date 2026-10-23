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

    private function wooValues(): array
    {
        return [
            'storeUrl' => 'https://shop.example',
            'consumerKey' => 'ck_live_abcdefghijklmnop',
            'consumerSecret' => 'cs_live_zyxwvutsrq123456',
        ];
    }

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

        $this->assertSame('…3456', $credential->hint);
    }

    #[Test]
    public function a_ciphertext_moved_to_another_credential_will_not_decrypt(): void
    {

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

        $raw = base64_decode($credential->ciphertext, true);
        $raw[40] = $raw[40] === 'a' ? 'b' : 'a';
        $credential->forceFill(['ciphertext' => base64_encode($raw)])->save();

        $this->expectException(VaultException::class);
        $this->vault()->reveal($credential->fresh(), 'test: tamper');
    }

    #[Test]
    public function every_write_uses_a_fresh_data_key(): void
    {

        $app = $this->app();

        $first = $this->vault()->put($app, 'woocommerce', $this->wooValues(), null);
        $firstKey = $first->wrapped_key;

        $second = $this->vault()->put($app, 'woocommerce', $this->wooValues(), null);

        $this->assertNotSame($firstKey, $second->fresh()->wrapped_key);
    }

    #[Test]
    public function the_same_secret_encrypts_differently_every_time(): void
    {

        $one = $this->vault()->put($this->app(), 'woocommerce', $this->wooValues(), null);
        $two = $this->vault()->put($this->app(), 'woocommerce', $this->wooValues(), null);

        $this->assertNotSame($one->ciphertext, $two->ciphertext);
    }

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

        $app = $this->app();
        $credential = $this->vault()->put($app, 'woocommerce', $this->wooValues(), null);
        $credential->forceFill(['wrapped_key' => base64_encode(random_bytes(60))])->save();

        try {
            $this->vault()->reveal($credential->fresh(), 'test: broken key');
        } catch (VaultException) {

        }

        $this->assertDatabaseHas('credential_accesses', [
            'credential_id' => $credential->id,
            'action' => 'reveal_failed',
        ]);
    }

    #[Test]
    public function a_reveal_without_a_stated_purpose_is_refused(): void
    {

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

        }

        $this->expectException(\RuntimeException::class);
        $record->delete();
    }

    #[Test]
    public function forgetting_a_credential_destroys_the_key_that_opens_it(): void
    {

        $app = $this->app();
        $credential = $this->vault()->put($app, 'woocommerce', $this->wooValues(), null);
        $ciphertext = $credential->ciphertext;

        $this->vault()->forget($credential, null);

        $this->assertDatabaseCount('app_credentials', 0);
        $this->assertDatabaseHas('credential_accesses', ['action' => 'deleted']);

        $this->assertNotEmpty($ciphertext);
    }

    #[Test]
    public function deleting_an_app_takes_its_credentials_with_it(): void
    {
        $app = $this->app();
        $this->vault()->put($app, 'woocommerce', $this->wooValues(), null);

        $app->forceDelete();

        $this->assertDatabaseCount('app_credentials', 0);

        $this->assertDatabaseHas('credential_accesses', ['action' => 'created']);
    }

    #[Test]
    public function key_material_never_survives_serialisation(): void
    {
        $credential = $this->vault()->put($this->app(), 'woocommerce', $this->wooValues(), null);

        $json = $credential->toJson();
        $this->assertStringNotContainsString('ciphertext', $json);
        $this->assertStringNotContainsString('wrapped_key', $json);

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
