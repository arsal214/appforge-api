<?php

namespace Tests\Feature;

use App\Models\App;
use App\Models\BusinessProfile;
use App\Models\Organization;
use App\Models\User;
use AppForge\Schema\Contract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BusinessProfileTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = [], array $extra = []): array
    {
        return array_merge([
            'category' => 'restaurant',
            'name' => 'Sams Pizza',
            'data' => array_merge(['businessName' => 'Sams Pizza'], $overrides),
        ], $extra);
    }

    private function onlyProfileId(): string
    {
        return (string) DB::table('business_profiles')->orderBy('id')->value('id');
    }

    #[Test]
    public function a_customer_with_nothing_saved_gets_an_empty_list(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/api/profiles')
            ->assertOk()
            ->assertExactJson(['data' => []]);
    }

    #[Test]
    public function a_profile_that_does_not_exist_is_a_404(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/api/profiles/bzp_01jzzzzzzzzzzzzzzzzzzzzzzz')
            ->assertNotFound();
    }

    #[Test]
    public function saving_then_reading_returns_the_same_data(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/profiles', $this->payload([
                'tagline' => 'Fresh pizza, fast',
                'currencySymbol' => '£',
                'menuGroups' => [[
                    'id' => 'grp_pizza',
                    'name' => 'Pizza',
                    'entries' => [[
                        'id' => 'itm_margherita',
                        'title' => 'Margherita',
                        'price' => '£8.50',
                    ]],
                ]],
            ]))
            ->assertCreated()
            ->assertJsonPath('data.category', 'restaurant')
            ->assertJsonPath('data.industry', 'restaurant')
            ->assertJsonPath('data.name', 'Sams Pizza')
            ->assertJsonPath('data.data.menuGroups.0.entries.0.title', 'Margherita');

        $id = $this->onlyProfileId();
        $this->forgetAuthentication();

        $this->actingAs($user)
            ->getJson("/api/profiles/{$id}")
            ->assertOk()
            ->assertJsonPath('data.data.tagline', 'Fresh pizza, fast');
    }

    #[Test]
    public function saving_the_same_profile_twice_updates_one_row(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/profiles', $this->payload(['tagline' => 'First']))
            ->assertCreated();

        $id = $this->onlyProfileId();
        $this->forgetAuthentication();

        $this->actingAs($user)
            ->putJson("/api/profiles/{$id}", $this->payload(['tagline' => 'Second']))
            ->assertOk()
            ->assertJsonPath('data.data.tagline', 'Second');

        $this->assertSame(
            1,
            DB::table('business_profiles')->where('organization_id', $user->organization_id)->count(),
        );
    }

    #[Test]
    public function one_customer_can_keep_two_businesses_of_the_same_kind(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/profiles', $this->payload(
                ['businessName' => 'Mill Lane'],
                ['name' => 'Mill Lane'],
            ))
            ->assertCreated();

        $this->forgetAuthentication();

        $this->actingAs($user)
            ->postJson('/api/profiles', $this->payload(
                ['businessName' => 'Station Road'],
                ['name' => 'Station Road'],
            ))
            ->assertCreated();

        $this->forgetAuthentication();

        $saved = $this->actingAs($user)->getJson('/api/profiles')->assertOk()->json('data');

        $this->assertCount(2, $saved, 'the second restaurant replaced the first');
        $this->assertSame(['Mill Lane', 'Station Road'], array_column($saved, 'name'));
        $this->assertSame(
            ['Mill Lane', 'Station Road'],
            array_column(array_column($saved, 'data'), 'businessName'),
            'both exist but they are sharing one set of details',
        );
    }

    #[Test]
    public function two_businesses_of_one_kind_cannot_share_a_name(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/profiles', $this->payload([], ['name' => 'Mill Lane']))
            ->assertCreated();

        $this->forgetAuthentication();

        $this->actingAs($user)
            ->postJson('/api/profiles', $this->payload([], ['name' => 'Mill Lane']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    #[Test]
    public function a_business_has_to_be_given_a_name(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/profiles', $this->payload([], ['name' => '   ']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    #[Test]
    public function there_is_a_ceiling_on_how_many_can_be_saved(): void
    {
        $user = User::factory()->create();
        $limit = Contract::limit('maxBusinessProfiles');

        BusinessProfile::factory()->count($limit)->create([
            'organization_id' => $user->organization_id,
        ]);

        $this->actingAs($user)
            ->postJson('/api/profiles', $this->payload([], ['name' => 'One too many']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    #[Test]
    public function a_customer_can_keep_profiles_for_several_categories(): void
    {
        $user = User::factory()->create();

        foreach (['restaurant', 'cafe', 'gym'] as $category) {
            $this->forgetAuthentication();
            $this->actingAs($user)
                ->postJson('/api/profiles', $this->payload([], ['category' => $category]))
                ->assertCreated();
        }

        $this->forgetAuthentication();

        $this->actingAs($user)
            ->getJson('/api/profiles')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.category', 'cafe')
            ->assertJsonPath('data.1.category', 'gym')
            ->assertJsonPath('data.2.category', 'restaurant');
    }

    #[Test]
    public function a_category_outside_the_contract_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/profiles', $this->payload([], ['category' => 'nightclub']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('category');
    }

    #[Test]
    public function deleting_one_that_is_not_there_is_a_404(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->deleteJson('/api/profiles/bzp_01jzzzzzzzzzzzzzzzzzzzzzzz')
            ->assertNotFound();
    }

    #[Test]
    public function deleting_a_saved_business_leaves_the_apps_it_filled_in(): void
    {
        $user = User::factory()->create();
        $profile = BusinessProfile::factory()->create([
            'organization_id' => $user->organization_id,
        ]);
        $app = App::factory()->create([
            'organization_id' => $user->organization_id,
            'business_profile_id' => $profile->id,
        ]);

        $this->actingAs($user)
            ->deleteJson("/api/profiles/{$profile->id}")
            ->assertOk();

        $this->assertSame(
            1,
            DB::table('apps')->where('id', $app->id)->whereNull('deleted_at')->count(),
        );
        $this->assertNull(DB::table('apps')->where('id', $app->id)->value('business_profile_id'));
    }

    #[Test]
    public function a_cleared_field_is_stored_as_absent_rather_than_null(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->postJson('/api/profiles', $this->payload([
                'tagline' => '',
                'about' => '',
                'phone' => '',
                'logoUrl' => '',
            ]))
            ->assertCreated();

        $stored = $response->json('data.data');

        foreach (['tagline', 'about', 'phone', 'logoUrl'] as $key) {
            $this->assertArrayNotHasKey($key, $stored, "{$key} was stored as a blank");
        }
        $this->assertSame('Sams Pizza', $stored['businessName']);
    }

    #[Test]
    public function a_blank_address_line_is_dropped_rather_than_refused(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->postJson('/api/profiles', $this->payload([
                'addressLines' => ['', '12 High Street', '', 'London'],
            ]))
            ->assertCreated();

        $this->assertSame(
            ['12 High Street', 'London'],
            $response->json('data.data.addressLines'),
        );
    }

    #[Test]
    public function false_and_zero_survive_the_blank_strip(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->postJson('/api/profiles', $this->payload([
                'openingHours' => [
                    ['day' => 'Mon-Sat', 'hours' => '11:00-23:00', 'closed' => false],
                    ['day' => 'Sunday', 'hours' => '', 'closed' => true],
                ],
            ]))
            ->assertCreated();

        $rows = $response->json('data.data.openingHours');

        $this->assertFalse($rows[0]['closed'], 'closed => false was stripped');
        $this->assertTrue($rows[1]['closed']);
        $this->assertArrayNotHasKey('hours', $rows[1]);
    }

    #[Test]
    public function an_empty_group_list_does_not_become_a_stored_blank(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->postJson('/api/profiles', $this->payload([
                'menuGroups' => [],
                'socialLinks' => [],
            ]))
            ->assertCreated();

        $stored = $response->json('data.data');
        $this->assertArrayNotHasKey('menuGroups', $stored);
        $this->assertArrayNotHasKey('socialLinks', $stored);
    }

    #[Test]
    public function another_organisations_profile_is_invisible(): void
    {
        $user = User::factory()->create();
        $theirs = BusinessProfile::factory()->restaurant()->for(Organization::factory())->create();

        $this->actingAs($user)
            ->getJson("/api/profiles/{$theirs->id}")
            ->assertNotFound();

        $this->forgetAuthentication();

        $this->actingAs($user)
            ->getJson('/api/profiles')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    #[Test]
    public function saving_does_not_overwrite_another_organisations_profile(): void
    {
        $user = User::factory()->create();
        $theirs = BusinessProfile::factory()->restaurant()->for(Organization::factory())->create();

        $this->actingAs($user)
            ->putJson("/api/profiles/{$theirs->id}", $this->payload(['businessName' => 'Hijacked']))
            ->assertNotFound();

        $this->assertSame(
            'Sams Pizza',
            $theirs->fresh()->data['businessName'],
            'a save created or updated a row belonging to another organisation',
        );
    }

    #[Test]
    public function deleting_does_not_touch_another_organisations_profile(): void
    {
        $user = User::factory()->create();
        $theirs = BusinessProfile::factory()->restaurant()->for(Organization::factory())->create();

        $this->actingAs($user)
            ->deleteJson("/api/profiles/{$theirs->id}")
            ->assertNotFound();

        $this->assertNotNull($theirs->fresh(), 'another organisation\'s profile was deleted');
    }

    #[Test]
    public function the_endpoints_require_authentication(): void
    {
        $this->getJson('/api/profiles')->assertUnauthorized();
        $this->getJson('/api/profiles/restaurant')->assertUnauthorized();
        $this->postJson('/api/profiles', $this->payload())->assertUnauthorized();
        $this->deleteJson('/api/profiles/restaurant')->assertUnauthorized();
    }

    #[Test]
    public function an_unknown_key_is_refused(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/profiles', $this->payload([
                'stripeSecretKey' => 'sk_live_not_on_my_watch',
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('data.stripeSecretKey');
    }

    #[Test]
    public function a_field_belonging_to_another_category_is_refused(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/profiles', $this->payload([
                'classTimetable' => [['day' => 'Monday', 'time' => '18:00', 'name' => 'Spin']],
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('data.classTimetable');

        $this->forgetAuthentication();

        $this->actingAs($user)
            ->postJson('/api/profiles', $this->payload(
                ['classTimetable' => [['day' => 'Monday', 'time' => '18:00', 'name' => 'Spin']]],
                ['category' => 'gym'],
            ))
            ->assertCreated();
    }

    #[Test]
    public function a_javascript_url_is_refused_everywhere_it_can_appear(): void
    {
        $user = User::factory()->create();

        $cases = [
            'data.websiteUrl' => $this->payload(['websiteUrl' => 'javascript:alert(1)']),
            'data.logoUrl' => $this->payload(['logoUrl' => 'javascript:alert(1)']),
            'data.reservationUrl' => $this->payload(['reservationUrl' => 'javascript:alert(1)']),
        ];

        foreach ($cases as $path => $payload) {
            $this->forgetAuthentication();
            $this->actingAs($user)
                ->postJson('/api/profiles', $payload)
                ->assertStatus(422)
                ->assertJsonValidationErrors($path);
        }

        $this->forgetAuthentication();

        $this->actingAs($user)
            ->postJson('/api/profiles', $this->payload([
                'menuGroups' => [[
                    'id' => 'grp_pizza',
                    'name' => 'Pizza',
                    'entries' => [[
                        'id' => 'itm_x',
                        'title' => 'Margherita',
                        'imageUrl' => 'javascript:alert(1)',
                    ]],
                ]],
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('data.menuGroups.0.entries.0.imageUrl');
    }

    #[Test]
    public function errors_are_keyed_by_the_path_of_the_field_that_is_wrong(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/profiles', $this->payload([
                'menuGroups' => [[
                    'id' => 'grp_pizza',
                    'name' => 'Pizza',
                    'entries' => [
                        ['id' => 'itm_ok', 'title' => 'Margherita'],
                        ['id' => 'itm_bad', 'title' => ''],
                    ],
                ]],
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('data.menuGroups.0.entries.1.title')
            ->assertJsonMissingValidationErrors('data.menuGroups.0.entries.0.title');
    }

    #[Test]
    public function a_business_name_is_required_but_nothing_else_is(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/profiles', $this->payload([], ['data' => []]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('data.businessName');

        $this->forgetAuthentication();

        $this->actingAs($user)
            ->postJson('/api/profiles', $this->payload())
            ->assertCreated();
    }

    #[Test]
    public function opening_hours_need_times_unless_the_day_is_closed(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/profiles', $this->payload([
                'openingHours' => [
                    ['day' => 'Monday', 'hours' => '', 'closed' => false],
                    ['day' => 'Sunday', 'hours' => '', 'closed' => true],
                ],
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('data.openingHours.0.hours')
            ->assertJsonMissingValidationErrors('data.openingHours.1.hours');
    }

    #[Test]
    public function duplicate_ids_within_a_group_are_refused(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/profiles', $this->payload([
                'menuGroups' => [[
                    'id' => 'grp_pizza',
                    'name' => 'Pizza',
                    'entries' => [
                        ['id' => 'itm_same', 'title' => 'Margherita'],
                        ['id' => 'itm_same', 'title' => 'Pepperoni'],
                    ],
                ]],
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('data.menuGroups.0.entries.1.id');
    }

    #[Test]
    public function an_oversized_profile_is_refused_before_it_is_walked(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/profiles', $this->payload([
                'about' => str_repeat('a', 600_000),
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('data');
    }

    #[Test]
    public function an_entry_can_be_marked_popular(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->postJson('/api/profiles', $this->payload([
                'menuGroups' => [[
                    'id' => 'grp_pizza',
                    'name' => 'Pizza',
                    'entries' => [
                        ['id' => 'itm_marg', 'title' => 'Margherita', 'popular' => true],
                        ['id' => 'itm_pep', 'title' => 'Pepperoni', 'popular' => false],
                        ['id' => 'itm_cal', 'title' => 'Calzone'],
                    ],
                ]],
            ]))
            ->assertCreated();

        $entries = $response->json('data.data.menuGroups.0.entries');

        $this->assertTrue($entries[0]['popular']);
        $this->assertFalse($entries[1]['popular'], 'an explicit false was stripped');
        $this->assertArrayNotHasKey('popular', $entries[2]);
    }

    #[Test]
    public function popular_has_to_be_a_boolean(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/profiles', $this->payload([
                'menuGroups' => [[
                    'id' => 'grp_pizza',
                    'name' => 'Pizza',
                    'entries' => [
                        ['id' => 'itm_marg', 'title' => 'Margherita', 'popular' => 'yes'],
                    ],
                ]],
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('data.menuGroups.0.entries.0.popular');
    }

    #[Test]
    public function an_unknown_social_platform_is_refused(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/profiles', $this->payload([
                'socialLinks' => [['platform' => 'myspace', 'url' => 'https://example.com']],
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('data.socialLinks.0.platform');
    }
}
