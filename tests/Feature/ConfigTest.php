<?php

namespace Tests\Feature;

use App\Models\App;
use App\Models\Organization;
use App\Models\User;
use App\Support\Config\ConfigValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ConfigTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A minimal config that passes. Every test that wants an invalid one starts
     * here and breaks exactly one thing, so a failure names its own cause.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function config(array $overrides = []): array
    {
        return array_replace([
            'schemaVersion' => 1,
            'minShellVersion' => '1.0.0',
            'app' => ['name' => 'Sams Pizza', 'slug' => 'sams-pizza'],
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
                        [
                            'id' => 'sec_hero',
                            'type' => 'hero',
                            'props' => [
                                'title' => 'Fresh pizza, fast',
                                'ctaLabel' => 'Order now',
                                'ctaAction' => ['type' => 'navigate', 'screenId' => 'scr_home'],
                            ],
                        ],
                    ],
                ],
            ],
        ], $overrides);
    }

    private function errors(array $config): array
    {
        return (new ConfigValidator)->validate($config);
    }

    /* --- The validator ------------------------------------------------- */

    #[Test]
    public function the_baseline_config_is_valid(): void
    {
        // If this ever fails, every other test in this file is meaningless.
        $this->assertSame([], $this->errors($this->config()));
    }

    #[Test]
    public function an_unexpected_field_anywhere_is_rejected(): void
    {
        // This is the rule that keeps credentials out of configs. A config is
        // served to an app binary anyone can unpack, so a stray `apiKey` is a
        // published secret.
        $withSecret = $this->config();
        $withSecret['shopifyAccessToken'] = 'shpat_deadbeef';

        $this->assertArrayHasKey('config.shopifyAccessToken', $this->errors($withSecret));

        $nested = $this->config();
        $nested['screens'][0]['sections'][0]['props']['woocommerceSecret'] = 'cs_live_x';

        $this->assertArrayHasKey(
            'config.screens.0.sections.0.props.woocommerceSecret',
            $this->errors($nested),
        );
    }

    #[Test]
    public function a_javascript_url_is_rejected(): void
    {
        // The panel preview renders these same components through
        // react-native-web in a real browser, so this is stored XSS before it
        // is anything else.
        foreach (['javascript:alert(1)', 'data:text/html,<script>', 'file:///etc/passwd'] as $url) {
            $config = $this->config();
            $config['screens'][0]['sections'][0]['props']['ctaAction'] = [
                'type' => 'openUrl',
                'url' => $url,
            ];

            $this->assertArrayHasKey(
                'config.screens.0.sections.0.props.ctaAction.url',
                $this->errors($config),
                "expected {$url} to be rejected",
            );
        }
    }

    #[Test]
    public function an_action_outside_the_closed_vocabulary_is_rejected(): void
    {
        // There is no expression language to extend. Adding a capability means
        // adding a variant to the schema and shipping a shell that understands
        // it — not accepting a new string here.
        foreach (['eval', 'runScript', 'navigate2', ''] as $type) {
            $config = $this->config();
            $config['screens'][0]['sections'][0]['props']['ctaAction'] = ['type' => $type];

            $this->assertArrayHasKey(
                'config.screens.0.sections.0.props.ctaAction.type',
                $this->errors($config),
                "expected action type '{$type}' to be rejected",
            );
        }
    }

    #[Test]
    public function a_navigate_action_pointing_nowhere_is_rejected(): void
    {
        // Silent failure on a real phone: the button just does nothing.
        $config = $this->config();
        $config['screens'][0]['sections'][0]['props']['ctaAction'] = [
            'type' => 'navigate',
            'screenId' => 'scr_does_not_exist',
        ];

        $this->assertArrayHasKey(
            'config.screens.0.sections.0.props.ctaAction.screenId',
            $this->errors($config),
        );
    }

    #[Test]
    public function a_navigate_action_may_point_forward_to_a_later_screen(): void
    {
        // Resolution happens in a second pass, so ordering in the config must
        // not matter.
        $config = $this->config();
        $config['screens'][0]['sections'][0]['props']['ctaAction'] = [
            'type' => 'navigate',
            'screenId' => 'scr_menu',
        ];
        $config['screens'][] = ['id' => 'scr_menu', 'title' => 'Menu', 'sections' => []];

        $this->assertSame([], $this->errors($config));
    }

    #[Test]
    public function a_tab_pointing_at_a_missing_screen_is_rejected(): void
    {
        $config = $this->config();
        $config['tabs'][0]['screenId'] = 'scr_ghost';

        $this->assertArrayHasKey('config.tabs.0.screenId', $this->errors($config));
    }

    #[Test]
    public function an_unknown_tab_icon_is_rejected(): void
    {
        // The shell ships a fixed icon set; anything else is a blank square.
        $config = $this->config();
        $config['tabs'][0]['icon'] = 'rocket';

        $this->assertArrayHasKey('config.tabs.0.icon', $this->errors($config));
    }

    #[Test]
    public function duplicate_screen_ids_are_rejected(): void
    {
        $config = $this->config();
        $config['screens'][] = ['id' => 'scr_home', 'title' => 'Copy', 'sections' => []];

        $this->assertArrayHasKey('config.screens.1.id', $this->errors($config));
    }

    #[Test]
    public function an_unknown_section_type_is_rejected(): void
    {
        $config = $this->config();
        $config['screens'][0]['sections'][0]['type'] = 'iframe';

        $this->assertArrayHasKey('config.screens.0.sections.0.type', $this->errors($config));
    }

    #[Test]
    public function a_wrong_schema_version_is_rejected(): void
    {
        $this->assertArrayHasKey('config.schemaVersion', $this->errors($this->config(['schemaVersion' => 2])));
        $this->assertArrayHasKey('config.schemaVersion', $this->errors($this->config(['schemaVersion' => '1'])));
    }

    #[Test]
    public function a_config_larger_than_the_cap_is_rejected(): void
    {
        // It is downloaded over mobile data at every cold start.
        $config = $this->config();
        $config['screens'][0]['sections'][0]['props']['subtitle'] = str_repeat('x', 300_000);

        $this->assertArrayHasKey('config', $this->errors($config));
    }

    #[Test]
    public function a_deeply_nested_config_is_rejected_before_anything_walks_it(): void
    {
        // `[[[[[…` is two bytes per level, so this sits well under the size cap
        // while being deep enough to blow PHP's stack during the navigation
        // walk. Depth has to be checked first, not caught later.
        $nested = [];
        for ($i = 0; $i < 5_000; $i++) {
            $nested = [$nested];
        }
        $config = $this->config();
        $config['screens'][0]['sections'][0]['props']['title'] = 'ok';
        $config['junk'] = $nested;

        $this->assertArrayHasKey('config', $this->errors($config));
    }

    #[Test]
    public function a_section_whose_props_are_all_optional_may_be_empty(): void
    {
        // Regression guard: json_decode turns both {} and [] into the same
        // empty PHP array, and a naive is-this-a-map check rejects it.
        $config = $this->config();
        $config['screens'][0]['sections'][] = [
            'id' => 'sec_contact',
            'type' => 'contact_block',
            'props' => [],
        ];

        $this->assertSame([], $this->errors($config));
    }

    /* --- The endpoints -------------------------------------------------- */

    private function ownApp(User $user): App
    {
        return App::factory()->create(['organization_id' => $user->organization_id]);
    }

    #[Test]
    public function a_draft_round_trips(): void
    {
        $user = User::factory()->create();
        $app = $this->ownApp($user);

        $this->actingAs($user)
            ->getJson("/api/apps/{$app->id}/config")
            ->assertOk()
            ->assertJsonPath('data.config', null);

        $this->actingAs($user)
            ->putJson("/api/apps/{$app->id}/config", ['config' => $this->config()])
            ->assertOk()
            ->assertJsonPath('data.config.app.slug', 'sams-pizza');

        $this->actingAs($user)
            ->getJson("/api/apps/{$app->id}/config")
            ->assertOk()
            ->assertJsonPath('data.config.app.slug', 'sams-pizza');

        // One draft per app, overwritten — not one row per save.
        $this->actingAs($user)
            ->putJson("/api/apps/{$app->id}/config", ['config' => $this->config()])
            ->assertOk();

        $this->assertDatabaseCount('app_configs', 1);
    }

    #[Test]
    public function saving_an_invalid_draft_is_a_422_keyed_by_path(): void
    {
        $user = User::factory()->create();
        $app = $this->ownApp($user);
        $config = $this->config();
        $config['screens'][0]['sections'][0]['props']['title'] = '';

        $this->actingAs($user)
            ->putJson("/api/apps/{$app->id}/config", ['config' => $config])
            ->assertStatus(422)
            // The path is what lets the panel highlight the offending control
            // instead of saying "invalid" and leaving the customer to hunt.
            ->assertJsonValidationErrors(['config.screens.0.sections.0.props.title']);

        $this->assertDatabaseCount('app_configs', 0);
    }

    #[Test]
    public function another_organisations_config_is_404(): void
    {
        $user = User::factory()->create();
        $theirs = App::factory()->for(Organization::factory())->create();

        $this->actingAs($user)->getJson("/api/apps/{$theirs->id}/config")->assertNotFound();
        $this->actingAs($user)
            ->putJson("/api/apps/{$theirs->id}/config", ['config' => $this->config()])
            ->assertNotFound();
        $this->actingAs($user)->getJson("/api/apps/{$theirs->id}/versions")->assertNotFound();
        $this->actingAs($user)->postJson("/api/apps/{$theirs->id}/versions")->assertNotFound();
    }

    #[Test]
    public function publishing_freezes_the_draft_and_numbers_it(): void
    {
        $user = User::factory()->create();
        $app = $this->ownApp($user);

        $this->actingAs($user)
            ->putJson("/api/apps/{$app->id}/config", ['config' => $this->config()])
            ->assertOk();

        $this->actingAs($user)
            ->postJson("/api/apps/{$app->id}/versions", ['notes' => 'First cut'])
            ->assertCreated()
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.notes', 'First cut');

        $this->actingAs($user)
            ->postJson("/api/apps/{$app->id}/versions")
            ->assertCreated()
            ->assertJsonPath('data.version', 2);

        // `ready` means publishable, not published. Only a build that a human
        // shepherded through review sets `live`.
        $this->assertSame('ready', $app->fresh()->status);
        $this->assertNull($app->fresh()->published_version);
    }

    #[Test]
    public function a_published_version_does_not_change_when_the_draft_does(): void
    {
        $user = User::factory()->create();
        $app = $this->ownApp($user);

        $this->actingAs($user)->putJson("/api/apps/{$app->id}/config", ['config' => $this->config()]);
        $this->actingAs($user)->postJson("/api/apps/{$app->id}/versions")->assertCreated();

        $changed = $this->config();
        $changed['app']['name'] = 'Renamed Entirely';
        $this->actingAs($user)->putJson("/api/apps/{$app->id}/config", ['config' => $changed])->assertOk();

        // A version is what a phone downloaded. If editing the draft could
        // reach back into it, a rollback would have nothing to roll back to.
        $version = $app->versions()->first();
        $this->assertSame('Sams Pizza', $version->config['app']['name']);
    }

    #[Test]
    public function publishing_without_a_draft_is_refused(): void
    {
        $user = User::factory()->create();
        $app = $this->ownApp($user);

        $this->actingAs($user)
            ->postJson("/api/apps/{$app->id}/versions")
            ->assertStatus(422);
    }

    #[Test]
    public function the_version_list_omits_the_config_blob(): void
    {
        $user = User::factory()->create();
        $app = $this->ownApp($user);

        $this->actingAs($user)->putJson("/api/apps/{$app->id}/config", ['config' => $this->config()]);
        $this->actingAs($user)->postJson("/api/apps/{$app->id}/versions");

        $data = $this->actingAs($user)
            ->getJson("/api/apps/{$app->id}/versions")
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $data);
        $this->assertEqualsCanonicalizing(
            ['id', 'appId', 'version', 'schemaVersion', 'notes', 'createdAt'],
            array_keys($data[0]),
        );
    }

    #[Test]
    public function there_is_no_route_to_edit_or_delete_a_version(): void
    {
        $user = User::factory()->create();
        $app = $this->ownApp($user);

        // 404 rather than 405: no route matches that URI for *any* method, so
        // the path does not exist at all. A 405 would mean "this resource is
        // here, just not editable that way", which is a weaker statement than
        // the one this test is pinning.
        $this->actingAs($user)->putJson("/api/apps/{$app->id}/versions/1")->assertNotFound();
        $this->actingAs($user)->deleteJson("/api/apps/{$app->id}/versions/1")->assertNotFound();
    }
}
