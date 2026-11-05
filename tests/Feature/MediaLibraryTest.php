<?php

namespace Tests\Feature;

use App\Models\App as ClientApp;
use App\Models\AppConfig;
use App\Models\AppVersion;
use App\Models\BusinessProfile;
use App\Models\Organization;
use App\Models\Upload;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MediaLibraryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function upload(User $user, string $name = 'dish.png'): Upload
    {
        $this->actingAs($user)
            ->postJson('/api/uploads', [
                'file' => UploadedFile::fake()->image($name, 400, 400),
            ])
            ->assertCreated();

        $upload = Upload::query()->latest('id')->firstOrFail();

        $this->forgetAuthentication();

        return $upload;
    }

    private function recorded(Upload $upload): bool
    {
        return DB::table('uploads')->where('id', $upload->id)->exists();
    }

    private function configUsing(Upload $upload): array
    {
        return [
            'screens' => [[
                'id' => 'home',
                'sections' => [[
                    'id' => 'sec-1',
                    'type' => 'image_banner',
                    'props' => ['imageUrl' => $upload->reference(), 'aspectRatio' => 1.9],
                ]],
            ]],
        ];
    }

    #[Test]
    public function an_upload_is_recorded_not_just_written_to_disk(): void
    {
        $user = User::factory()->create();
        $upload = $this->upload($user, 'front-of-shop.png');

        $this->assertSame($user->organization_id, $upload->organization_id);
        $this->assertSame('front-of-shop.png', $upload->name);
        $this->assertSame('image/png', $upload->mime);
        $this->assertGreaterThan(0, $upload->bytes);
        $this->assertTrue(Storage::disk('public')->exists($upload->path));
    }

    #[Test]
    public function the_recorded_path_is_the_form_a_config_holds(): void
    {
        $user = User::factory()->create();
        $upload = $this->upload($user);

        $this->assertStringStartsWith('/storage/', $upload->reference());
        $this->assertStringNotContainsString('http', $upload->reference());
    }

    #[Test]
    public function the_listing_shows_what_this_organisation_owns(): void
    {
        $user = User::factory()->create();
        $this->upload($user, 'one.png');
        $this->upload($user, 'two.png');

        $names = $this->actingAs($user)
            ->getJson('/api/uploads')
            ->assertOk()
            ->json('data.uploads.*.name');

        $this->assertSame(['two.png', 'one.png'], $names, 'newest should come first');
    }

    #[Test]
    public function the_library_hands_back_one_page_and_says_there_is_another(): void
    {
        $user = User::factory()->create();

        for ($i = 1; $i <= 61; $i++) {
            Upload::factory()
                ->inOrganization($user->organization)
                ->create(['name' => "image-{$i}.png"]);
        }

        $first = $this->actingAs($user)
            ->getJson('/api/uploads')
            ->assertOk()
            ->assertJsonPath('data.hasMore', true)
            ->json('data.uploads');

        $this->assertCount(60, $first);

        $this->forgetAuthentication();

        $second = $this->actingAs($user)
            ->getJson('/api/uploads?before='.end($first)['id'])
            ->assertOk()
            ->assertJsonPath('data.hasMore', false)
            ->json('data.uploads');

        $this->assertCount(1, $second, 'the oldest image could not be reached');
        $this->assertSame('image-1.png', $second[0]['name']);
    }

    #[Test]
    public function the_listing_never_shows_another_organisations_images(): void
    {
        $mine = User::factory()->create();
        $theirs = User::factory()->create();

        $this->upload($theirs, 'not-mine.png');
        $this->upload($mine, 'mine.png');

        $names = $this->actingAs($mine)
            ->getJson('/api/uploads')
            ->assertOk()
            ->json('data.uploads.*.name');

        $this->assertSame(['mine.png'], $names);
    }

    #[Test]
    public function deleting_another_organisations_image_is_a_404_not_a_403(): void
    {
        $mine = User::factory()->create();
        $theirs = User::factory()->create();
        $hers = $this->upload($theirs);

        $this->actingAs($mine)
            ->deleteJson("/api/uploads/{$hers->id}")
            ->assertNotFound();

        $this->assertTrue(Storage::disk('public')->exists($hers->path));
    }

    #[Test]
    public function an_unused_image_is_deleted_from_the_record_and_the_disk(): void
    {
        $user = User::factory()->create();
        $upload = $this->upload($user);

        $this->actingAs($user)
            ->deleteJson("/api/uploads/{$upload->id}")
            ->assertOk();

        $this->assertFalse($this->recorded($upload));
        $this->assertFalse(Storage::disk('public')->exists($upload->path));
    }

    #[Test]
    public function an_image_a_draft_is_using_cannot_be_deleted(): void
    {
        $user = User::factory()->create();
        $upload = $this->upload($user);

        $app = ClientApp::factory()->create([
            'organization_id' => $user->organization_id,
            'name' => 'Menu Moment',
        ]);

        AppConfig::create([
            'app_id' => $app->id,
            'schema_version' => 1,
            'config' => $this->configUsing($upload),
        ]);

        $this->actingAs($user)
            ->deleteJson("/api/uploads/{$upload->id}")
            ->assertStatus(409)
            ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'Menu Moment'));

        $this->assertTrue($this->recorded($upload));
        $this->assertTrue(Storage::disk('public')->exists($upload->path));
    }

    #[Test]
    public function an_image_only_an_old_published_version_uses_cannot_be_deleted(): void
    {
        $user = User::factory()->create();
        $upload = $this->upload($user);

        $app = ClientApp::factory()->create([
            'organization_id' => $user->organization_id,
            'name' => 'Rollback Cafe',
        ]);

        AppConfig::create([
            'app_id' => $app->id,
            'schema_version' => 1,
            'config' => ['screens' => []],
        ]);

        AppVersion::create([
            'app_id' => $app->id,
            'version' => 3,
            'schema_version' => 1,
            'config' => $this->configUsing($upload),
        ]);

        $this->actingAs($user)
            ->deleteJson("/api/uploads/{$upload->id}")
            ->assertStatus(409)
            ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'Rollback Cafe'));
    }

    #[Test]
    public function an_image_an_app_in_the_bin_uses_cannot_be_deleted(): void
    {
        $user = User::factory()->create();
        $upload = $this->upload($user);

        $app = ClientApp::factory()->create([
            'organization_id' => $user->organization_id,
            'name' => 'Binned Bistro',
        ]);

        AppConfig::create([
            'app_id' => $app->id,
            'schema_version' => 1,
            'config' => $this->configUsing($upload),
        ]);

        $app->delete();

        $this->actingAs($user)
            ->deleteJson("/api/uploads/{$upload->id}")
            ->assertStatus(409)
            ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'Bin'));
    }

    #[Test]
    public function an_image_a_business_profile_uses_cannot_be_deleted(): void
    {
        $user = User::factory()->create();
        $upload = $this->upload($user);

        BusinessProfile::create([
            'organization_id' => $user->organization_id,
            'category' => 'restaurant',
            'name' => 'Sams',
            'data' => ['businessName' => 'Sams', 'logoUrl' => $upload->reference()],
        ]);

        $this->actingAs($user)
            ->deleteJson("/api/uploads/{$upload->id}")
            ->assertStatus(409)
            ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'restaurant'));
    }

    #[Test]
    public function a_staff_member_cannot_delete_an_image(): void
    {
        $owner = User::factory()->create();
        $upload = $this->upload($owner);

        $staff = User::factory()->create([
            'organization_id' => $owner->organization_id,
            'role' => 'staff',
        ]);

        $this->actingAs($staff)
            ->deleteJson("/api/uploads/{$upload->id}")
            ->assertForbidden();

        $this->assertTrue($this->recorded($upload));
    }

    #[Test]
    public function the_sweep_reports_an_unused_image_but_changes_nothing(): void
    {
        $user = User::factory()->create();
        $upload = $this->upload($user);
        $upload->forceFill(['created_at' => now()->subDays(30)])->save();

        $this->artisan('appforge:sweep-uploads')
            ->expectsOutputToContain($upload->name)
            ->expectsOutputToContain('Nothing was deleted')
            ->assertSuccessful();

        $this->assertTrue($this->recorded($upload));
        $this->assertTrue(Storage::disk('public')->exists($upload->path));
    }

    #[Test]
    public function the_sweep_deletes_an_unused_image_when_told_to(): void
    {
        $user = User::factory()->create();
        $upload = $this->upload($user);
        $upload->forceFill(['created_at' => now()->subDays(30)])->save();

        $this->artisan('appforge:sweep-uploads --delete')->assertSuccessful();

        $this->assertFalse($this->recorded($upload));
        $this->assertFalse(Storage::disk('public')->exists($upload->path));
    }

    #[Test]
    public function the_sweep_leaves_a_recent_upload_alone(): void
    {
        $user = User::factory()->create();
        $upload = $this->upload($user);

        $this->artisan('appforge:sweep-uploads --delete')->assertSuccessful();

        $this->assertTrue($this->recorded($upload));
        $this->assertTrue(Storage::disk('public')->exists($upload->path));
    }

    #[Test]
    public function the_sweep_leaves_an_image_that_is_still_in_use(): void
    {
        $user = User::factory()->create();
        $upload = $this->upload($user);
        $upload->forceFill(['created_at' => now()->subDays(30)])->save();

        $app = ClientApp::factory()->create(['organization_id' => $user->organization_id]);

        AppConfig::create([
            'app_id' => $app->id,
            'schema_version' => 1,
            'config' => $this->configUsing($upload),
        ]);

        $this->artisan('appforge:sweep-uploads --delete')->assertSuccessful();

        $this->assertTrue($this->recorded($upload));
        $this->assertTrue(Storage::disk('public')->exists($upload->path));
    }

    #[Test]
    public function the_sweep_reports_a_file_it_has_no_record_of(): void
    {
        User::factory()->create();

        Storage::disk('public')->put('profile-images/stray/mystery.png', 'x');

        $this->artisan('appforge:sweep-uploads --delete')
            ->expectsOutputToContain('mystery.png')
            ->expectsOutputToContain('no record')
            ->assertSuccessful();

        $this->assertTrue(
            Storage::disk('public')->exists('profile-images/stray/mystery.png'),
            'the sweep deleted a file it could not attribute to anyone',
        );
    }

    #[Test]
    public function the_backfill_adopts_images_that_were_already_on_disk(): void
    {
        $organization = Organization::factory()->create();
        $disk = Storage::disk('public');

        $disk->put("profile-images/{$organization->id}/old-one.jpg", 'xxxx');
        $disk->put("profile-images/{$organization->id}/old-two.png", 'xxxx');
        $disk->put('profile-images/org_nobody/orphaned.jpg', 'xxxx');

        $migration = require database_path('migrations/2026_11_04_140000_create_uploads_table.php');
        (fn () => $this->adoptWhatIsAlreadyOnDisk())->call($migration);

        $rows = DB::table('uploads')->orderBy('name')->get();

        $this->assertSame(
            ['old-one.jpg', 'old-two.png'],
            $rows->pluck('name')->all(),
        );

        $this->assertSame(
            $organization->id,
            $rows->first()->organization_id,
            'the owner was not taken from the directory the file sits in',
        );

        $this->assertSame(
            0,
            DB::table('uploads')->where('name', 'orphaned.jpg')->count(),
            'a file under an unknown organisation was given an owner anyway',
        );
    }
}
