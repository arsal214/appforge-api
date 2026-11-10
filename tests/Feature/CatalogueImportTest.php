<?php

namespace Tests\Feature;

use App\Models\AccountEvent;
use App\Models\App;
use App\Models\User;
use App\Support\Config\ConfigValidator;
use App\Support\Import\CatalogueWriter;
use App\Support\Import\ItemId;
use App\Support\Import\SafeUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CatalogueImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SafeUrl::resolveUsing(fn (string $host): array => match ($host) {
            'shop.example' => ['93.184.216.34'],
            'sams-pizza.myshopify.com' => ['23.227.38.74'],
            default => [],
        });
    }

    protected function tearDown(): void
    {
        SafeUrl::resolveNormally();

        parent::tearDown();
    }

    private function ownApp(User $user): App
    {
        return App::factory()->create(['organization_id' => $user->organization_id]);
    }

    private function connectWoo(User $user, App $app, string $storeUrl = 'https://shop.example'): void
    {
        $this->actingAs($user)
            ->putJson("/api/apps/{$app->id}/credentials/woocommerce", [
                'storeUrl' => $storeUrl,
                'consumerKey' => 'ck_live_key_value_1234',
                'consumerSecret' => 'cs_live_super_secret_value_9f2b',
            ])
            ->assertCreated();
    }

    private function configWithCatalogue(User $user, App $app, array $popular = []): void
    {
        $config = [
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
                            'id' => 'sec_popular',
                            'type' => 'product_grid',
                            'props' => [
                                'columns' => 2,
                                'showPrices' => true,
                                'source' => ['kind' => 'literal', 'items' => $popular],
                            ],
                        ],
                        [
                            'id' => 'sec_menu',
                            'type' => 'product_list',
                            'props' => [
                                'showImages' => true,
                                'source' => ['kind' => 'literal', 'items' => [
                                    ['id' => 'itm_old', 'title' => 'Typed by hand'],
                                ]],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $this->actingAs($user)
            ->putJson("/api/apps/{$app->id}/config", ['config' => $config])
            ->assertOk();
    }

    private function wooProducts(array $products): void
    {
        Http::fake([
            '*/wp-json/wc/v3/data/currencies/current*' => Http::response(['code' => 'GBP'], 200),
            '*/wp-json/wc/v3/products*' => Http::response($products, 200),
        ]);
    }

    #[Test]
    public function products_from_a_store_become_the_apps_menu(): void
    {
        $user = User::factory()->create();
        $app = $this->ownApp($user);

        $this->connectWoo($user, $app);
        $this->configWithCatalogue($user, $app);

        $this->wooProducts([
            [
                'id' => 412,
                'name' => 'Margherita',
                'price' => '8.5',
                'short_description' => '<p>Tomato, mozzarella &amp; basil</p>',
                'images' => [['src' => 'https://shop.example/margherita.jpg']],
            ],
            ['id' => 413, 'name' => 'Garlic bread', 'price' => '4'],
        ]);

        $body = $this->actingAs($user)
            ->postJson("/api/apps/{$app->id}/catalogue/import", ['type' => 'woocommerce'])
            ->assertOk()
            ->json('data');

        $this->assertSame(2, $body['imported']);
        $this->assertFalse($body['capped']);

        $items = $this->menuItems($app);

        $this->assertCount(2, $items);
        $this->assertSame('Margherita', $items[0]['title']);
        $this->assertSame('£8.50', $items[0]['price'], 'the price is not pre-formatted with the store currency');
        $this->assertSame('Tomato, mozzarella & basil', $items[0]['subtitle'], 'the HTML was not stripped');
        $this->assertSame('https://shop.example/margherita.jpg', $items[0]['imageUrl']);
        $this->assertSame('£4.00', $items[1]['price']);

        $this->assertNotContains('Typed by hand', array_column($items, 'title'));
    }

    #[Test]
    public function the_key_goes_in_a_header_and_never_in_the_address(): void
    {
        $user = User::factory()->create();
        $app = $this->ownApp($user);

        $this->connectWoo($user, $app);
        $this->configWithCatalogue($user, $app);
        $this->wooProducts([['id' => 1, 'name' => 'A thing', 'price' => '1']]);

        $this->actingAs($user)
            ->postJson("/api/apps/{$app->id}/catalogue/import", ['type' => 'woocommerce'])
            ->assertOk();

        Http::assertSent(function ($request): bool {
            return ! str_contains($request->url(), 'cs_live')
                && ! str_contains($request->url(), 'consumer_secret')
                && $request->hasHeader('Authorization');
        });
    }

    #[Test]
    public function a_second_import_updates_the_items_instead_of_replacing_them(): void
    {
        $user = User::factory()->create();
        $app = $this->ownApp($user);

        $this->connectWoo($user, $app);

        $this->configWithCatalogue($user, $app, [
            ['id' => ItemId::for('woo', '412'), 'title' => 'Margherita', 'price' => '£8.50'],
        ]);

        $this->wooProducts([
            ['id' => 412, 'name' => 'Margherita', 'price' => '9.00'],
            ['id' => 413, 'name' => 'Garlic bread', 'price' => '4'],
        ]);

        $this->actingAs($user)
            ->postJson("/api/apps/{$app->id}/catalogue/import", ['type' => 'woocommerce'])
            ->assertOk();

        $popular = $this->sectionItems($app, 'sec_popular');

        $this->assertCount(
            1,
            $popular,
            'the hand-picked grid was emptied by an import, which stable ids exist to prevent',
        );
        $this->assertSame('£9.00', $popular[0]['price'], 'the kept item did not take the new price');
    }

    #[Test]
    public function an_address_inside_the_private_network_is_refused(): void
    {
        $user = User::factory()->create();
        $app = $this->ownApp($user);

        $this->connectWoo($user, $app, 'https://127.0.0.1/shop');
        $this->configWithCatalogue($user, $app);

        Http::fake();

        $this->actingAs($user)
            ->postJson("/api/apps/{$app->id}/catalogue/import", ['type' => 'woocommerce'])
            ->assertStatus(422)
            ->assertJson(['reason' => 'blocked_host']);

        Http::assertNothingSent();
    }

    #[Test]
    public function a_name_that_does_not_resolve_is_refused_rather_than_fetched(): void
    {
        $user = User::factory()->create();
        $app = $this->ownApp($user);

        $this->connectWoo($user, $app, 'https://not-a-real-store.example');
        $this->configWithCatalogue($user, $app);

        Http::fake();

        $this->actingAs($user)
            ->postJson("/api/apps/{$app->id}/catalogue/import", ['type' => 'woocommerce'])
            ->assertStatus(422)
            ->assertJson(['reason' => 'unknown_host']);

        Http::assertNothingSent();
    }

    #[Test]
    public function the_guard_refuses_every_shape_of_private_address(): void
    {
        foreach ([
            '127.0.0.1', '10.0.0.5', '192.168.1.1', '172.16.9.9',
            '169.254.169.254', '0.0.0.0', '::1', '::ffff:127.0.0.1',
        ] as $address) {
            $this->assertTrue(
                SafeUrl::isPrivate($address),
                "{$address} was treated as a public address",
            );
        }

        $this->assertFalse(SafeUrl::isPrivate('93.184.216.34'));
    }

    #[Test]
    public function plain_http_is_refused_because_the_key_would_travel_in_the_open(): void
    {
        $user = User::factory()->create();
        $app = $this->ownApp($user);

        $this->connectWoo($user, $app, 'http://shop.example');
        $this->configWithCatalogue($user, $app);

        Http::fake();

        $this->actingAs($user)
            ->postJson("/api/apps/{$app->id}/catalogue/import", ['type' => 'woocommerce'])
            ->assertStatus(422)
            ->assertJson(['reason' => 'insecure_store_url']);

        Http::assertNothingSent();
    }

    #[Test]
    public function a_redirect_is_a_failure_rather_than_a_hop_to_follow(): void
    {
        $user = User::factory()->create();
        $app = $this->ownApp($user);

        $this->connectWoo($user, $app);
        $this->configWithCatalogue($user, $app);

        Http::fake([
            '*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data/']),
        ]);

        $this->actingAs($user)
            ->postJson("/api/apps/{$app->id}/catalogue/import", ['type' => 'woocommerce'])
            ->assertStatus(422)
            ->assertJson(['reason' => 'redirected']);
    }

    #[Test]
    public function a_store_that_refuses_the_key_says_so_in_words_a_customer_can_act_on(): void
    {
        $user = User::factory()->create();
        $app = $this->ownApp($user);

        $this->connectWoo($user, $app);
        $this->configWithCatalogue($user, $app);

        Http::fake(['*' => Http::response(['message' => 'Sorry, you cannot list resources.'], 401)]);

        $this->actingAs($user)
            ->postJson("/api/apps/{$app->id}/catalogue/import", ['type' => 'woocommerce'])
            ->assertStatus(422)
            ->assertJson(['reason' => 'rejected'])
            ->assertJsonFragment(['message' => 'Your store refused the key. Check the consumer key and secret, and that the key has read access.']);
    }

    #[Test]
    public function more_products_than_a_config_can_hold_are_capped_and_the_customer_is_told(): void
    {
        $user = User::factory()->create();
        $app = $this->ownApp($user);

        $this->connectWoo($user, $app);
        $this->configWithCatalogue($user, $app);

        $this->wooProducts(array_map(
            fn (int $n): array => ['id' => $n, 'name' => "Thing {$n}", 'price' => '1'],
            range(1, 80),
        ));

        $body = $this->actingAs($user)
            ->postJson("/api/apps/{$app->id}/catalogue/import", ['type' => 'woocommerce'])
            ->assertOk()
            ->json('data');

        $this->assertSame(60, $body['imported']);
        $this->assertTrue($body['capped'], 'the customer was not told products were left out');
        $this->assertSame(60, $body['limit']);

        $this->assertSame(
            [],
            (new ConfigValidator)->validate($app->fresh()->config->config),
            'an import wrote a config the validator refuses',
        );
    }

    #[Test]
    public function an_app_with_nowhere_to_put_products_says_what_to_do(): void
    {
        $user = User::factory()->create();
        $app = $this->ownApp($user);

        $this->connectWoo($user, $app);

        $this->actingAs($user)->putJson("/api/apps/{$app->id}/config", [
            'config' => [
                'schemaVersion' => 1,
                'minShellVersion' => '1.0.0',
                'app' => ['name' => 'Sams Pizza', 'slug' => 'sams-pizza'],
                'theme' => [
                    'accentColor' => '#0f766e', 'backgroundColor' => '#ffffff',
                    'surfaceColor' => '#f8fafc', 'textColor' => '#0f172a',
                    'mutedTextColor' => '#64748b', 'borderColor' => '#e2e8f0',
                    'cornerRadius' => 12,
                ],
                'tabs' => [['id' => 'tab_home', 'label' => 'Home', 'icon' => 'home', 'screenId' => 'scr_home']],
                'screens' => [[
                    'id' => 'scr_home',
                    'title' => 'Home',
                    'sections' => [['id' => 'sec_hero', 'type' => 'hero', 'props' => ['title' => 'Hello']]],
                ]],
            ],
        ])->assertOk();

        $this->wooProducts([['id' => 1, 'name' => 'A thing', 'price' => '1']]);

        $this->actingAs($user)
            ->postJson("/api/apps/{$app->id}/catalogue/import", ['type' => 'woocommerce'])
            ->assertStatus(422)
            ->assertJson(['reason' => 'nowhere_to_put_them']);
    }

    #[Test]
    public function importing_without_connecting_the_store_first_is_refused_under_the_panels_own_key(): void
    {
        $user = User::factory()->create();
        $app = $this->ownApp($user);

        $this->configWithCatalogue($user, $app);

        $this->actingAs($user)
            ->postJson("/api/apps/{$app->id}/catalogue/import", ['type' => 'woocommerce'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['type']);
    }

    #[Test]
    public function the_import_is_written_to_the_accounts_history(): void
    {
        $user = User::factory()->create();
        $app = $this->ownApp($user);

        $this->connectWoo($user, $app);
        $this->configWithCatalogue($user, $app);
        $this->wooProducts([['id' => 1, 'name' => 'A thing', 'price' => '1']]);

        $this->actingAs($user)
            ->postJson("/api/apps/{$app->id}/catalogue/import", ['type' => 'woocommerce'])
            ->assertOk();

        $event = AccountEvent::where('action', 'catalogue.imported')->sole();

        $this->assertSame($app->getKey(), $event->subject_id);
        $this->assertSame('woocommerce', $event->context['from']);
        $this->assertSame(1, $event->context['items']);
        $this->assertSame('apps', AccountEvent::groupOf($event->action));
    }

    #[Test]
    public function importing_into_a_rejected_app_puts_it_back_to_a_draft(): void
    {
        $user = User::factory()->create();
        $app = $this->ownApp($user);

        $this->connectWoo($user, $app);
        $this->configWithCatalogue($user, $app);
        $app->update(['status' => 'rejected']);

        $this->wooProducts([['id' => 1, 'name' => 'A thing', 'price' => '1']]);

        $this->actingAs($user)
            ->postJson("/api/apps/{$app->id}/catalogue/import", ['type' => 'woocommerce'])
            ->assertOk();

        $this->assertSame(
            'draft',
            $app->fresh()->status,
            'a rejected app stayed rejected after the customer fixed it by importing',
        );
    }

    #[Test]
    public function another_organisations_app_is_a_404_not_a_403(): void
    {
        $mine = User::factory()->create();
        $theirs = User::factory()->create();
        $theirApp = $this->ownApp($theirs);

        $this->actingAs($mine)
            ->postJson("/api/apps/{$theirApp->id}/catalogue/import", ['type' => 'woocommerce'])
            ->assertNotFound();
    }

    #[Test]
    public function a_member_who_cannot_build_cannot_import(): void
    {
        $owner = User::factory()->create();
        $app = $this->ownApp($owner);

        $staff = User::factory()->create([
            'organization_id' => $owner->organization_id,
            'role' => 'staff',
        ]);

        $this->actingAs($staff)
            ->postJson("/api/apps/{$app->id}/catalogue/import", ['type' => 'woocommerce'])
            ->assertForbidden();
    }

    #[Test]
    public function a_shopify_shop_is_read_with_a_pinned_api_version(): void
    {
        $user = User::factory()->create();
        $app = $this->ownApp($user);

        $this->actingAs($user)
            ->putJson("/api/apps/{$app->id}/credentials/shopify", [
                'shopDomain' => 'https://sams-pizza.myshopify.com/admin',
                'accessToken' => 'shpat_0123456789abcdef',
            ])
            ->assertCreated();

        $this->configWithCatalogue($user, $app);

        Http::fake(['*' => Http::response(['products' => [[
            'id' => 99,
            'title' => 'Margherita',
            'body_html' => '<p>Tomato &amp; basil</p>',
            'variants' => [[
                'price' => '8.50',
                'presentment_prices' => [['price' => ['currency_code' => 'USD']]],
            ]],
            'image' => ['src' => 'https://cdn.shopify.com/margherita.jpg'],
        ]]], 200)]);

        $this->actingAs($user)
            ->postJson("/api/apps/{$app->id}/catalogue/import", ['type' => 'shopify'])
            ->assertOk()
            ->assertJsonPath('data.imported', 1);

        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/admin/api/2026-01/products.json')
            && $request->hasHeader('X-Shopify-Access-Token'));

        $items = $this->menuItems($app);

        $this->assertSame('Margherita', $items[0]['title']);
        $this->assertSame('$8.50', $items[0]['price']);
        $this->assertSame('Tomato & basil', $items[0]['subtitle']);
    }

    #[Test]
    public function the_writer_picks_the_same_block_the_panel_picks(): void
    {
        $config = ['screens' => [['sections' => [
            ['id' => 'sec_grid', 'type' => 'product_grid'],
            ['id' => 'sec_list', 'type' => 'product_list'],
        ]]]];

        $this->assertSame('sec_list', CatalogueWriter::targetId($config));

        $gridOnly = ['screens' => [['sections' => [
            ['id' => 'sec_grid', 'type' => 'product_grid'],
        ]]]];

        $this->assertSame('sec_grid', CatalogueWriter::targetId($gridOnly));

        $this->assertNull(CatalogueWriter::targetId(['screens' => [['sections' => []]]]));
    }

    private function menuItems(App $app): array
    {
        return $this->sectionItems($app, 'sec_menu');
    }

    private function sectionItems(App $app, string $sectionId): array
    {
        foreach ($app->fresh()->config->config['screens'] as $screen) {
            foreach ($screen['sections'] as $section) {
                if ($section['id'] === $sectionId) {
                    return $section['props']['source']['items'];
                }
            }
        }

        return [];
    }
}
