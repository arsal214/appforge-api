<?php

namespace Tests\Feature;

use App\Models\App;
use App\Models\BusinessProfile;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Upload;
use App\Models\User;
use App\Support\Tenant\TenantData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TenantDataTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'cs_live_do_not_export_me';

    private const PUSH_TOKEN = 'device-token-do-not-export-0123456789';

    /**
     * A business with one of everything, so an export that drops a table and an
     * erase that leaves one behind both show up.
     */
    private function furnished(User $owner): App
    {
        $app = App::factory()->create([
            'organization_id' => $owner->organization_id,
            'name' => 'Mill Lane Pizza',
        ]);

        BusinessProfile::factory()->create([
            'organization_id' => $owner->organization_id,
        ]);

        Upload::factory()->inOrganization($owner->organization)->create();

        $this->actingAs($owner)
            ->putJson("/api/apps/{$app->id}/credentials/woocommerce", [
                'storeUrl' => 'https://shop.example',
                'consumerKey' => 'ck_live_key_value_1234',
                'consumerSecret' => self::SECRET,
            ])->assertCreated();

        $this->actingAs($owner)
            ->putJson("/api/apps/{$app->id}/config", [
                'config' => $this->validConfig(),
            ])->assertOk();

        $this->postJson("/api/public/apps/{$app->id}/orders", [
            'lines' => [['itemId' => 'i-1', 'title' => 'Margherita', 'price' => '12.00', 'quantity' => 1]],
            'total' => '12.00',
            'details' => [['label' => 'Phone', 'value' => '+44 7700 900123', 'kind' => 'phone']],
        ])->assertCreated();

        $this->postJson("/api/public/apps/{$app->id}/devices", [
            'token' => self::PUSH_TOKEN,
            'platform' => 'android',
        ])->assertNoContent();

        $this->actingAs($owner)
            ->postJson("/api/apps/{$app->id}/push", [
                'title' => 'Open late tonight',
                'body' => 'Kitchen open until 11.',
            ])->assertCreated();

        $this->actingAs($owner)
            ->postJson("/api/apps/{$app->id}/versions")
            ->assertCreated();

        $this->postJson("/api/public/apps/{$app->id}/events", [
            'platform' => 'android',
            'events' => [['name' => 'app_open', 'count' => 3]],
        ])->assertSuccessful();

        return $app;
    }

    private function validConfig(): array
    {
        return [
            'schemaVersion' => 1,
            'minShellVersion' => '1.0.0',
            'app' => ['name' => 'Mill Lane Pizza', 'slug' => 'mill-lane-pizza'],
            'theme' => [
                'accentColor' => '#b45309',
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
        ];
    }

    /**
     * Closes the account and lets the wind-down run out, which is what the
     * scheduled command does on day 30. The tests below are about what erasing
     * removes; the grace period itself is covered in GracePeriodTest.
     */
    private function closeAndWaitItOut(User $owner): void
    {
        $this->actingAs($owner)
            ->deleteJson('/api/organization', ['name' => $owner->organization->name])
            ->assertOk();

        $this->travel(Organization::GRACE_DAYS + 1)->days();

        $this->artisan('appforge:close-accounts')->assertSuccessful();

        $this->travelBack();
    }

    #[Test]
    public function the_table_list_covers_every_place_tenant_data_is_kept(): void
    {
        $unaccounted = [];

        foreach (DB::select('SHOW TABLES') as $row) {
            $table = array_values((array) $row)[0];

            $columns = array_map(
                fn ($column) => $column->Field,
                DB::select('SHOW COLUMNS FROM '.$table),
            );

            if (array_intersect($columns, ['organization_id', 'app_id']) === []) {
                continue;
            }

            if (! array_key_exists($table, TenantData::TABLES)) {
                $unaccounted[] = $table;
            }
        }

        $this->assertSame(
            [],
            $unaccounted,
            'these tables hold tenant data and TenantData::TABLES has not been told what happens to them on erase',
        );
    }

    #[Test]
    public function an_owner_can_take_a_copy_of_everything_the_business_has(): void
    {
        $owner = User::factory()->create();
        $app = $this->furnished($owner);

        $export = $this->actingAs($owner)
            ->getJson('/api/account/export')
            ->assertOk()
            ->json('data');

        $this->assertSame($owner->organization->name, $export['organization']['name']);
        $this->assertSame($owner->email, $export['people'][0]['email']);
        $this->assertCount(1, $export['businesses']);
        $this->assertCount(1, $export['images']);

        $this->assertCount(1, $export['apps']);
        $only = $export['apps'][0];

        $this->assertSame($app->id, $only['id']);
        $this->assertSame('Mill Lane Pizza', $only['config']['app']['name']);
        $this->assertSame('+44 7700 900123', $only['records'][0]['details'][0]['value']);
        $this->assertSame('Open late tonight', $only['notifications'][0]['title']);
        $this->assertSame(['woocommerce'], $only['credentials']);
        $this->assertSame(['ios' => 0, 'android' => 1], $only['installs']);
        $this->assertCount(1, $only['versions']);
        $this->assertNotSame([], $only['visits']);
    }

    #[Test]
    public function an_export_carries_no_secret_a_customer_entrusted_to_us(): void
    {
        $owner = User::factory()->create();
        $this->furnished($owner);

        $raw = $this->actingAs($owner)
            ->getJson('/api/account/export')
            ->assertOk()
            ->content();

        $this->assertStringNotContainsString(self::SECRET, $raw);
        $this->assertStringNotContainsString(self::PUSH_TOKEN, $raw);
        $this->assertStringNotContainsString($owner->password, $raw);
    }

    #[Test]
    public function an_export_is_only_ever_this_organisation(): void
    {
        $mine = User::factory()->create();
        $theirs = User::factory()->create();

        App::factory()->create([
            'organization_id' => $theirs->organization_id,
            'name' => 'Not My Shop',
        ]);
        Upload::factory()->inOrganization($theirs->organization)->create();
        BusinessProfile::factory()->create(['organization_id' => $theirs->organization_id]);

        $export = $this->actingAs($mine)
            ->getJson('/api/account/export')
            ->assertOk();

        $export->assertJsonCount(0, 'data.apps');
        $export->assertJsonCount(0, 'data.images');
        $export->assertJsonCount(0, 'data.businesses');
        $export->assertJsonCount(1, 'data.people');

        $this->assertStringNotContainsString('Not My Shop', $export->content());
    }

    #[Test]
    public function only_the_owner_can_export_or_close_the_account(): void
    {
        foreach (['editor', 'staff'] as $role) {
            $user = User::factory()->create(['role' => $role]);

            $this->actingAs($user)->getJson('/api/account/export')->assertForbidden();

            $this->actingAs($user)
                ->deleteJson('/api/organization', ['name' => $user->organization->name])
                ->assertForbidden();

            $this->assertDatabaseHas('organizations', [
                'id' => $user->organization_id,
                'closing_at' => null,
            ]);
        }
    }

    #[Test]
    public function closing_the_account_needs_the_name_typed_exactly(): void
    {
        $owner = User::factory()->create();
        $this->furnished($owner);

        foreach (['', 'mill lane pizza', 'Something Else'] as $attempt) {
            $this->actingAs($owner)
                ->deleteJson('/api/organization', ['name' => $attempt])
                ->assertJsonValidationErrors('name');
        }

        $this->assertDatabaseHas('organizations', [
            'id' => $owner->organization_id,
            'closing_at' => null,
        ]);
        $this->assertDatabaseCount('apps', 1);
    }

    #[Test]
    public function closing_the_account_takes_every_last_row_with_it(): void
    {
        Storage::fake('public');

        $owner = User::factory()->create();
        $app = $this->furnished($owner);

        $upload = Upload::where('organization_id', $owner->organization_id)->firstOrFail();
        Storage::disk('public')->put($upload->path, 'an image');

        $token = $owner->createToken('phone')->plainTextToken;
        DB::table('password_reset_tokens')->insert([
            'email' => $owner->email,
            'token' => 'reset-token',
            'created_at' => now(),
        ]);

        $this->closeAndWaitItOut($owner);

        foreach (array_keys(TenantData::TABLES) as $table) {
            if (TenantData::TABLES[$table] === 'kept') {
                continue;
            }

            $left = match ($table) {
                'password_reset_tokens' => DB::table($table)->where('email', $owner->email)->count(),
                'personal_access_tokens' => DB::table($table)->where('tokenable_id', $owner->id)->count(),
                'sessions' => DB::table($table)->where('user_id', $owner->id)->count(),
                'users' => DB::table($table)->where('organization_id', $owner->organization_id)->count(),
                'account_events', 'apps', 'business_profiles', 'uploads', 'organization_invites', 'credential_accesses' => DB::table($table)->where('organization_id', $owner->organization_id)->count(),
                default => DB::table($table)->where('app_id', $app->id)->count(),
            };

            $this->assertSame(0, $left, "{$table} still holds rows for a closed account");
        }

        $this->assertDatabaseMissing('organizations', ['id' => $owner->organization_id]);

        Storage::disk('public')->assertMissing($upload->path);

        // The guard still holds the user `actingAs` put there; a real request
        // after the delete starts with nothing but the token.
        $this->forgetAuthentication();

        $this->assertSame(
            401,
            $this->withHeader('Authorization', "Bearer {$token}")
                ->getJson('/api/apps')
                ->getStatusCode(),
        );
    }

    #[Test]
    public function closing_one_account_leaves_everyone_else_untouched(): void
    {
        Storage::fake('public');

        $mine = User::factory()->create();
        $this->furnished($mine);

        $theirs = User::factory()->create();
        $theirApp = $this->furnished($theirs);
        $theirUpload = Upload::where('organization_id', $theirs->organization_id)->firstOrFail();
        Storage::disk('public')->put($theirUpload->path, 'their image');

        $this->closeAndWaitItOut($mine);

        $this->assertDatabaseHas('organizations', ['id' => $theirs->organization_id]);
        $this->assertDatabaseHas('apps', ['id' => $theirApp->id]);
        $this->assertDatabaseHas('users', ['id' => $theirs->id]);
        $this->assertSame(1, Order::where('app_id', $theirApp->id)->count());
        Storage::disk('public')->assertExists($theirUpload->path);

        $this->actingAs($theirs)->getJson('/api/apps')->assertOk()->assertJsonCount(1, 'data');
    }

    #[Test]
    public function an_app_in_the_bin_is_exported_and_then_erased_like_any_other(): void
    {
        $owner = User::factory()->create();
        $app = $this->furnished($owner);

        $this->actingAs($owner)->deleteJson("/api/apps/{$app->id}")->assertOk();

        $export = $this->actingAs($owner)
            ->getJson('/api/account/export')
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $export['apps'], 'an app in the bin fell out of the export');
        $this->assertTrue($export['apps'][0]['inBin']);

        $this->closeAndWaitItOut($owner);

        $this->assertSame(0, App::withTrashed()->where('id', $app->id)->count());
    }

    #[Test]
    public function what_our_own_staff_did_to_the_account_is_not_erased_by_the_request(): void
    {
        $owner = User::factory()->create();
        $this->furnished($owner);

        DB::table('audit_logs')->insert([
            'id' => 'aud_'.strtolower((string) Str::ulid()),
            'admin_email' => 'staff@appforge.test',
            'action' => 'app.config.viewed',
            'organization_id' => $owner->organization_id,
            'created_at' => now(),
        ]);

        $this->closeAndWaitItOut($owner);

        $this->assertSame(
            1,
            DB::table('audit_logs')->where('organization_id', $owner->organization_id)->count(),
            'the deletion request destroyed the record of what was done to the account',
        );
    }
}
