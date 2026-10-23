<?php

namespace Tests\Feature;

use App\Models\App;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CredentialApiTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'cs_live_super_secret_value_9f2b';

    private function ownApp(User $user): App
    {
        return App::factory()->create(['organization_id' => $user->organization_id]);
    }

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
