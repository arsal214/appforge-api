<?php

namespace Tests\Feature;

use App\Models\App;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AppsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_app_payload_matches_the_panels_zod_schema(): void
    {
        $user = User::factory()->create();
        $app = App::factory()->create(['organization_id' => $user->organization_id]);

        $data = $this->actingAs($user)
            ->getJson("/api/apps/{$app->id}")
            ->assertOk()
            ->json('data');

        $this->assertEqualsCanonicalizing([
            'id', 'organizationId', 'name', 'slug', 'sourceUrl', 'templateId',
            'industry', 'category', 'status', 'accentColor', 'publishedVersion',
            'createdAt', 'updatedAt',
        ], array_keys($data));

        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/',
            $data['createdAt'],
        );
    }

    #[Test]
    public function an_app_records_the_category_it_was_built_from(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/apps', [
                'name' => 'Bean There',
                'templateId' => 'tpl_restaurant',
                'sourceUrl' => null,
                'category' => 'cafe',
            ])
            ->assertCreated()
            ->assertJsonPath('data.category', 'cafe')
            ->assertJsonPath('data.industry', 'restaurant');
    }

    #[Test]
    public function an_app_built_from_a_bare_template_has_no_category(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/apps', [
                'name' => 'Something New',
                'templateId' => 'tpl_restaurant',
                'sourceUrl' => null,
            ])
            ->assertCreated()
            ->assertJsonPath('data.category', null);
    }

    #[Test]
    public function a_category_outside_the_contract_is_refused(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/apps', [
                'name' => 'Nightclub',
                'templateId' => 'tpl_restaurant',
                'sourceUrl' => null,
                'category' => 'nightclub',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('category');
    }

    #[Test]
    public function creating_an_app_takes_its_industry_and_colour_from_the_template(): void
    {
        $user = User::factory()->create();

        $data = $this->actingAs($user)->postJson('/api/apps', [
            'name' => 'Sams Pizza',
            'templateId' => 'tpl_restaurant',
            'sourceUrl' => 'https://samspizza.example',
        ])->assertCreated()->json('data');

        $this->assertSame('restaurant', $data['industry']);
        $this->assertSame('#b45309', $data['accentColor']);
        $this->assertSame('draft', $data['status']);
        $this->assertSame('sams-pizza', $data['slug']);
        $this->assertNull($data['publishedVersion']);
        $this->assertSame($user->organization_id, $data['organizationId']);
    }

    #[Test]
    public function an_unknown_template_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/apps', [
            'name' => 'Sams Pizza',
            'templateId' => 'tpl_made_up',
            'sourceUrl' => null,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['templateId']);
    }

    #[Test]
    public function a_null_source_url_is_accepted_but_a_junk_one_is_not(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/apps', [
            'name' => 'No Website',
            'templateId' => 'tpl_services',
            'sourceUrl' => null,
        ])->assertCreated()->assertJsonPath('data.sourceUrl', null);

        $this->actingAs($user)->postJson('/api/apps', [
            'name' => 'Bad Website',
            'templateId' => 'tpl_services',
            'sourceUrl' => 'javascript:alert(1)',
        ])->assertStatus(422)->assertJsonValidationErrors(['sourceUrl']);
    }

    #[Test]
    public function updating_can_clear_the_source_url(): void
    {
        $user = User::factory()->create();
        $app = App::factory()->create([
            'organization_id' => $user->organization_id,
            'source_url' => 'https://old.example',
        ]);

        $this->actingAs($user)
            ->patchJson("/api/apps/{$app->id}", ['sourceUrl' => null])
            ->assertOk()
            ->assertJsonPath('data.sourceUrl', null);
    }

    #[Test]
    public function updating_the_name_regenerates_the_slug(): void
    {
        $user = User::factory()->create();
        $app = App::factory()->create(['organization_id' => $user->organization_id]);

        $this->actingAs($user)
            ->patchJson("/api/apps/{$app->id}", ['name' => 'Brand New Name'])
            ->assertOk()
            ->assertJsonPath('data.slug', 'brand-new-name');
    }

    #[Test]
    public function a_non_hex_accent_colour_is_rejected(): void
    {
        $user = User::factory()->create();
        $app = App::factory()->create(['organization_id' => $user->organization_id]);

        $this->actingAs($user)
            ->patchJson("/api/apps/{$app->id}", ['accentColor' => 'teal'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['accentColor']);
    }

    #[Test]
    public function a_live_app_cannot_be_deleted_from_the_panel(): void
    {
        $user = User::factory()->create();
        $app = App::factory()->live()->create(['organization_id' => $user->organization_id]);

        $this->actingAs($user)
            ->deleteJson("/api/apps/{$app->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('apps', ['id' => $app->id, 'deleted_at' => null]);
    }

    #[Test]
    public function a_draft_app_can_be_deleted(): void
    {
        $user = User::factory()->create();
        $app = App::factory()->create(['organization_id' => $user->organization_id]);

        $this->actingAs($user)->deleteJson("/api/apps/{$app->id}")->assertOk();

        $this->assertSoftDeleted('apps', ['id' => $app->id]);
        $this->actingAs($user)->getJson("/api/apps/{$app->id}")->assertNotFound();
    }

    #[Test]
    public function a_deleted_app_is_in_the_bin_and_not_in_the_list(): void
    {
        $user = User::factory()->create();
        $kept = App::factory()->create(['organization_id' => $user->organization_id]);
        $binned = App::factory()->create(['organization_id' => $user->organization_id]);

        $this->actingAs($user)->deleteJson("/api/apps/{$binned->id}")->assertOk();

        $listed = $this->actingAs($user)->getJson('/api/apps')->assertOk()->json('data');
        $this->assertSame([$kept->id], array_column($listed, 'id'));

        $bin = $this->actingAs($user)->getJson('/api/apps/trashed')->assertOk()->json('data');
        $this->assertSame([$binned->id], array_column($bin, 'id'));
    }

    #[Test]
    public function a_deleted_app_can_be_brought_back(): void
    {
        $user = User::factory()->create();
        $app = App::factory()->create(['organization_id' => $user->organization_id]);

        $this->actingAs($user)->deleteJson("/api/apps/{$app->id}")->assertOk();

        $this->actingAs($user)
            ->postJson("/api/apps/{$app->id}/restore")
            ->assertOk()
            ->assertJsonPath('data.id', $app->id);

        $this->assertDatabaseHas('apps', ['id' => $app->id, 'deleted_at' => null]);
        $this->actingAs($user)->getJson("/api/apps/{$app->id}")->assertOk();

        $bin = $this->actingAs($user)->getJson('/api/apps/trashed')->assertOk()->json('data');
        $this->assertSame([], $bin);
    }

    #[Test]
    public function an_app_that_was_never_deleted_cannot_be_restored(): void
    {
        $user = User::factory()->create();
        $app = App::factory()->create(['organization_id' => $user->organization_id]);

        $this->actingAs($user)->postJson("/api/apps/{$app->id}/restore")->assertNotFound();
    }

    #[Test]
    public function another_organisations_bin_is_invisible(): void
    {
        $user = User::factory()->create();
        $other = Organization::factory()->create();
        $theirs = App::factory()->for($other)->create();
        $theirs->delete();

        $bin = $this->actingAs($user)->getJson('/api/apps/trashed')->assertOk()->json('data');
        $this->assertSame([], $bin);

        $this->actingAs($user)->postJson("/api/apps/{$theirs->id}/restore")->assertNotFound();
        $this->assertSoftDeleted('apps', ['id' => $theirs->id]);
    }

    #[Test]
    public function the_bin_needs_a_login(): void
    {
        $this->getJson('/api/apps/trashed')->assertUnauthorized();
    }

    #[Test]
    public function templates_are_public_and_match_the_panels_fixtures(): void
    {
        $data = $this->getJson('/api/templates')->assertOk()->json('data');

        $this->assertSame(
            ['tpl_ecommerce', 'tpl_restaurant', 'tpl_services'],
            array_column($data, 'id'),
        );
        $this->assertEqualsCanonicalizing(
            ['id', 'name', 'industry', 'tagline', 'description', 'accentColor'],
            array_keys($data[0]),
        );
    }

    #[Test]
    public function account_and_organisation_updates_return_the_updated_record(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->patchJson('/api/account', [
            'name' => 'Renamed Person',
            'email' => 'Renamed@Example.com',
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Renamed Person')
            ->assertJsonPath('data.email', 'renamed@example.com');

        $this->actingAs($user)->patchJson('/api/organization', [
            'name' => 'Renamed Business',
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Renamed Business')
            ->assertJsonPath('data.slug', 'renamed-business');
    }

    #[Test]
    public function changing_your_email_to_someone_elses_is_rejected(): void
    {
        $user = User::factory()->create();
        User::factory()->create(['email' => 'taken@example.com']);

        $this->actingAs($user)->patchJson('/api/account', [
            'name' => $user->name,
            'email' => 'taken@example.com',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);

        $this->actingAs($user)->patchJson('/api/account', [
            'name' => 'New Name',
            'email' => $user->email,
        ])->assertOk();
    }
}
