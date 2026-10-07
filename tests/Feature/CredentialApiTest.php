<?php

namespace Tests\Feature;

use App\Models\App;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The HTTP surface of the vault. The defining property under test is a
 * negative one: no response, on any route, ever contains a secret.
 */
class CredentialApiTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'cs_live_super_secret_value_9f2b';

    private function ownApp(User $user): App
    {
        return App::factory()->create(['organization_id' => $user->organization_id]);
    }

    /** @return array<string, string> */
    private function woo(): array
    {
        return [
            'storeUrl' => 'https://shop.example',
            'consumerKey' => 'ck_live_key_value_1234',
            'consumerSecret' => self::SECRET,
        ];
    }

    #[Test]
    public function a_credential_can_be_set_and_shows_as_configured(): void
    {
        $user = User::factory()->create();
        $app = $this->ownApp($user);

        $this->actingAs($user)
            ->putJson("/api/apps/{$app->id}/credentials/woocommerce", $this->woo())
            ->assertCreated();

        $body = $this->actingAs($user)
            ->getJson("/api/apps/{$app->id}/credentials")
            ->assertOk()
            ->json('data');

        $woo = collect($body['credentials'])->firstWhere('type', 'woocommerce');

        $this->assertTrue($woo['configured']);
        $this->assertSame('…9f2b', $woo['hint']);
    }

    #[Test]
    public function no_endpoint_returns_the_secret(): void
    {
        // The property this whole design exists to hold. Checked against the raw
        // response body, not a parsed field, so a secret leaking through any key
        // at any depth still fails.
        $user = User::factory()->create();
        $app = $this->ownApp($user);

        $this->actingAs($user)
            ->putJson("/api/apps/{$app->id}/credentials/woocommerce", $this->woo());

        foreach ([
            "/api/apps/{$app->id}/credentials",
            "/api/apps/{$app->id}",
            '/api/apps',
        ] as $path) {
            $response = $this->actingAs($user)->getJson($path);

            $this->assertStringNotContainsString(
                self::SECRET,
                $response->getContent(),
                "a secret leaked from {$path}",
            );
            $this->assertStringNotContainsString('ciphertext', $response->getContent());
            $this->assertStringNotContainsString('wrapped_key', $response->getContent());
        }
    }

    #[Test]
    public function there_is_no_route_that_reveals_a_credential(): void
    {
        // A reveal button is a screenshot, a support call, and a browser-history
        // entry waiting to happen. Decryption belongs to the proxy and the build
        // worker, not the panel.
        $user = User::factory()->create();
        $app = $this->ownApp($user);
        $this->actingAs($user)
            ->putJson("/api/apps/{$app->id}/credentials/woocommerce", $this->woo());

        foreach ([
            "/api/apps/{$app->id}/credentials/woocommerce",
            "/api/apps/{$app->id}/credentials/woocommerce/reveal",
            "/api/apps/{$app->id}/credentials/woocommerce/show",
        ] as $path) {
            $response = $this->actingAs($user)->getJson($path);

            // Asserting the property rather than a status code: 404 and 405 both
            // prove there is no such route, and pinning one of them would make
            // this test about Laravel's routing internals instead of about the
            // secret staying secret.
            $this->assertTrue(
                $response->getStatusCode() >= 400,
                "{$path} answered successfully",
            );
            $this->assertStringNotContainsString(
                self::SECRET,
                $response->getContent(),
                "a secret leaked from {$path}",
            );
        }
    }

    #[Test]
    public function a_partial_credential_is_rejected(): void
    {
        // A half-set credential fails at the worst possible moment — mid-build,
        // or mid-checkout for someone's customer.
        $user = User::factory()->create();
        $app = $this->ownApp($user);

        $this->actingAs($user)
            ->putJson("/api/apps/{$app->id}/credentials/woocommerce", [
                'storeUrl' => 'https://shop.example',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['consumerKey', 'consumerSecret']);
    }

    #[Test]
    public function an_unknown_credential_type_is_404(): void
    {
        $user = User::factory()->create();
        $app = $this->ownApp($user);

        $this->actingAs($user)
            ->putJson("/api/apps/{$app->id}/credentials/my_own_thing", ['x' => 'y'])
            ->assertNotFound();
    }

    #[Test]
    public function another_organisations_credentials_are_404(): void
    {
        $user = User::factory()->create();
        $theirs = App::factory()->for(Organization::factory())->create();

        $this->actingAs($user)
            ->getJson("/api/apps/{$theirs->id}/credentials")
            ->assertNotFound();

        $this->actingAs($user)
            ->putJson("/api/apps/{$theirs->id}/credentials/woocommerce", $this->woo())
            ->assertNotFound();

        $this->actingAs($user)
            ->deleteJson("/api/apps/{$theirs->id}/credentials/woocommerce")
            ->assertNotFound();
    }

    #[Test]
    public function the_panel_is_told_when_key_management_is_not_production_grade(): void
    {
        // Silently protecting real keys with a development key is precisely the
        // failure this flag exists to prevent.
        $user = User::factory()->create();
        $app = $this->ownApp($user);

        $this->actingAs($user)
            ->getJson("/api/apps/{$app->id}/credentials")
            ->assertOk()
            ->assertJsonPath('data.productionGrade', false);
    }

    #[Test]
    public function deleting_a_credential_leaves_the_access_record_behind(): void
    {
        $user = User::factory()->create();
        $app = $this->ownApp($user);
        $this->actingAs($user)
            ->putJson("/api/apps/{$app->id}/credentials/woocommerce", $this->woo());

        $this->actingAs($user)
            ->deleteJson("/api/apps/{$app->id}/credentials/woocommerce")
            ->assertOk();

        $this->assertDatabaseCount('app_credentials', 0);
        $this->assertDatabaseHas('credential_accesses', ['action' => 'deleted']);
    }

    #[Test]
    public function setting_a_credential_twice_rotates_rather_than_duplicates(): void
    {
        $user = User::factory()->create();
        $app = $this->ownApp($user);

        $this->actingAs($user)
            ->putJson("/api/apps/{$app->id}/credentials/woocommerce", $this->woo());
        $this->actingAs($user)
            ->putJson("/api/apps/{$app->id}/credentials/woocommerce", [
                ...$this->woo(),
                'consumerSecret' => 'cs_live_rotated_value_aaaa',
            ]);

        // A revoked key must not still be sitting in our database.
        $this->assertDatabaseCount('app_credentials', 1);

        $woo = collect(
            $this->actingAs($user)
                ->getJson("/api/apps/{$app->id}/credentials")
                ->json('data.credentials')
        )->firstWhere('type', 'woocommerce');

        $this->assertSame('…aaaa', $woo['hint']);
    }

    #[Test]
    public function credentials_require_authentication(): void
    {
        $app = App::factory()->for(Organization::factory())->create();

        $this->getJson("/api/apps/{$app->id}/credentials")->assertUnauthorized();
        $this->putJson("/api/apps/{$app->id}/credentials/woocommerce", $this->woo())
            ->assertUnauthorized();
    }
}
