<?php

namespace Tests\Feature;

use App\Models\App;
use App\Models\AppVersion;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PublicConfigTest extends TestCase
{
    use RefreshDatabase;

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

        $this->getJson("/api/public/configs/{$app->id}")->assertNotFound();
    }

    #[Test]
    public function an_unknown_app_and_an_unpublished_one_are_indistinguishable(): void
    {
        $unpublished = $this->app();

        $missing = $this->getJson('/api/public/configs/app_01jz00000000000000000000');
        $unpub = $this->getJson("/api/public/configs/{$unpublished->id}");

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

        $this->assertStringContainsString('max-age=60', (string) $response->headers->get('Cache-Control'));
        $this->assertSame('"'.$version->id.'"', $response->headers->get('ETag'));
    }

    #[Test]
    public function it_serves_the_shared_conformance_fixture_intact(): void
    {

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

        $this->assertEquals($config, $served, 'the config must survive the round trip');
    }
}
