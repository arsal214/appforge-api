<?php

namespace Tests\Feature;

use App\Models\App;
use App\Models\AppVersion;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The only unauthenticated endpoint that serves customer data. Every test here
 * is about what it must *not* say — and about the fact that it works at all.
 *
 * **Nothing in this file calls `actingAs`.** The first version did, publishing
 * through the authenticated API before fetching publicly, and every test passed
 * while the endpoint was completely broken: Laravel's RequestGuard kept that
 * user resolved into the later request, so `OrganizationScope` matched the right
 * organisation instead of failing closed the way it does in production. Fixtures
 * are built with models directly so that no request here has ever had a user.
 */
class PublicConfigTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private function config(string $name = 'Sams Pizza'): array
    {
        return [
            'schemaVersion' => 1,
            'minShellVersion' => '1.0.0',
            'app' => ['name' => $name, 'slug' => 'sams-pizza'],
            'theme' => [
                'accentColor' => '#0f766e',
                'backgroundColor' => '#ffffff',
                'surfaceColor' => '#f8fafc',
                'textColor' => '#0f172a',
                'mutedTextColor' => '#64748b',
                'borderColor' => '#e2e8f0',
                'cornerRadius' => 12,
            ],
            'tabs' => [
                ['id' => 'tab_home', 'label' => 'Home', 'icon' => 'home', 'screenId' => 'scr_home'],
            ],
            'screens' => [
                [
                    'id' => 'scr_home',
                    'title' => 'Home',
                    'sections' => [
                        ['id' => 'sec_hero', 'type' => 'hero', 'props' => ['title' => $name]],
                    ],
                ],
            ],
        ];
    }

    private function app(): App
    {
        return App::factory()->for(Organization::factory())->create();
    }

    private function publish(App $app, int $version, string $name): AppVersion
    {
        return AppVersion::create([
            'app_id' => $app->id,
            'version' => $version,
            'schema_version' => 1,
            'config' => $this->config($name),
        ]);
    }

    #[Test]
    public function a_published_config_is_served_with_no_authentication_at_all(): void
    {
        // The regression guard for the bug this file missed the first time: the
        // App model is tenant-scoped, and with no user that scope applies
        // `1 = 0`. If this controller ever goes back to querying it, this fails.
        $app = $this->app();
        $this->publish($app, 1, 'Sams Pizza');

        $this->assertNull(auth()->user(), 'this test must run with no user');

        $this->getJson("/api/public/configs/{$app->id}")
            ->assertOk()
            ->assertJsonPath('data.app.name', 'Sams Pizza')
            ->assertJsonPath('data.schemaVersion', 1);
    }

    #[Test]
    public function the_response_is_only_the_config(): void
    {
        $app = $this->app();
        $this->publish($app, 1, 'Sams Pizza');

        $body = $this->getJson("/api/public/configs/{$app->id}")->assertOk()->json();

        // An app id is guessable in principle. A response that also revealed the
        // owning organisation — or merely that this business is a customer of
        // ours — would be a leak on an endpoint with no authentication.
        $this->assertSame(['data'], array_keys($body));

        $flat = (string) json_encode($body);
        foreach (['organizationId', 'organization_id', 'status', 'templateId', 'sourceUrl', 'template_id'] as $leak) {
            $this->assertStringNotContainsString($leak, $flat);
        }
        $this->assertStringNotContainsString($app->organization_id, $flat);
    }

    #[Test]
    public function the_latest_published_version_wins(): void
    {
        $app = $this->app();
        $this->publish($app, 1, 'First');
        $this->publish($app, 2, 'Second');

        $this->getJson("/api/public/configs/{$app->id}")
            ->assertOk()
            ->assertJsonPath('data.app.name', 'Second');
    }

    #[Test]
    public function an_app_that_has_never_published_is_404(): void
    {
        $app = $this->app();

        $this->getJson("/api/public/configs/{$app->id}")->assertNotFound();
    }

    #[Test]
    public function a_deleted_app_stops_serving(): void
    {
        $app = $this->app();
        $this->publish($app, 1, 'Sams Pizza');

        $this->getJson("/api/public/configs/{$app->id}")->assertOk();

        $app->delete();

        // Soft deleted, so the row and its versions still exist. It must still
        // stop being served, or "delete my app" would not delete anything a
        // customer's own users could see.
        $this->getJson("/api/public/configs/{$app->id}")->assertNotFound();
    }

    #[Test]
    public function an_unknown_app_and_an_unpublished_one_are_indistinguishable(): void
    {
        $unpublished = $this->app();

        $missing = $this->getJson('/api/public/configs/app_01jz00000000000000000000');
        $unpub = $this->getJson("/api/public/configs/{$unpublished->id}");

        // Otherwise the endpoint is an oracle for which app ids exist.
        $missing->assertNotFound();
        $unpub->assertNotFound();
        $this->assertSame($missing->json(), $unpub->json());
    }

    #[Test]
    public function the_response_is_cacheable_and_carries_the_version_id_as_an_etag(): void
    {
        $app = $this->app();
        $version = $this->publish($app, 1, 'Sams Pizza');

        $response = $this->getJson("/api/public/configs/{$app->id}")->assertOk();

        // Every cold start of every app hits this. Without caching, launch
        // spikes land on the origin.
        $this->assertStringContainsString('max-age=60', (string) $response->headers->get('Cache-Control'));
        $this->assertSame('"'.$version->id.'"', $response->headers->get('ETag'));
    }

    #[Test]
    public function it_serves_the_shared_conformance_fixture_intact(): void
    {
        // End to end across three repos: the fixture appforge-schema owns, which
        // appforge-api validates, served in the shape appforge-shell accepts.
        $raw = file_get_contents(
            dirname(__DIR__, 2).'/../appforge-schema/fixtures/valid/every-section.json',
        );
        $config = json_decode((string) $raw, true, 512, JSON_THROW_ON_ERROR);
        unset($config['$comment']);

        $app = $this->app();
        AppVersion::create([
            'app_id' => $app->id,
            'version' => 1,
            'schema_version' => 1,
            'config' => $config,
        ]);

        $served = $this->getJson("/api/public/configs/{$app->id}")
            ->assertOk()
            ->json('data');

        // `assertEquals`, not `assertSame`: MySQL's `json` column type does not
        // store the document you gave it. It normalises to a binary form with
        // object keys sorted, so key order does not survive a round trip and a
        // byte-for-byte assertion is not available.
        //
        // Nothing depends on order — the shell reads by key, and the ETag is the
        // version id — but it is worth knowing before someone tries to hash a
        // config server-side and compare it to a hash taken on a device.
        $this->assertEquals($config, $served, 'the config must survive the round trip');
    }
}
