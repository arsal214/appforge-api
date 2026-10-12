<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    #[Test]
    public function a_png_is_accepted_and_comes_back_as_a_url(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->postJson('/api/uploads', [
                'file' => UploadedFile::fake()->image('dish.png', 800, 600),
            ])
            ->assertCreated();

        $url = $response->json('data.url');
        $this->assertIsString($url);
        $this->assertStringStartsWith('http', $url);

        $this->assertCount(1, Storage::disk('public')->allFiles());
    }

    #[Test]
    public function jpg_and_webp_are_accepted_too(): void
    {
        $user = User::factory()->create();

        foreach (['photo.jpg', 'photo.webp'] as $name) {
            $this->forgetAuthentication();
            $this->actingAs($user)
                ->postJson('/api/uploads', [
                    'file' => UploadedFile::fake()->image($name, 400, 400),
                ])
                ->assertCreated();
        }
    }

    #[Test]
    public function an_svg_is_refused(): void
    {
        $user = User::factory()->create();

        $svg = UploadedFile::fake()->createWithContent(
            'logo.svg',
            '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
        );

        $this->actingAs($user)
            ->postJson('/api/uploads', ['file' => $svg])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');

        $this->assertCount(0, Storage::disk('public')->allFiles());
    }

    #[Test]
    public function a_script_renamed_as_an_image_is_refused(): void
    {
        $user = User::factory()->create();

        $fake = UploadedFile::fake()->createWithContent(
            'payload.png',
            '<?php echo "owned"; ?>',
        );

        $this->actingAs($user)
            ->postJson('/api/uploads', ['file' => $fake])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');

        $this->assertCount(0, Storage::disk('public')->allFiles());
    }

    #[Test]
    public function an_image_too_large_on_either_side_is_refused(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/uploads', [
                'file' => UploadedFile::fake()->image('huge.png', 5000, 100),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');
    }

    #[Test]
    public function an_oversized_file_is_refused(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/uploads', [
                'file' => UploadedFile::fake()->image('big.jpg', 1200, 1200)->size(6144),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');
    }

    #[Test]
    public function sending_no_file_is_refused(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/uploads', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');
    }

    #[Test]
    public function the_stored_name_is_ours_not_the_customers(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->postJson('/api/uploads', [
                'file' => UploadedFile::fake()->image('../../../evil .png', 100, 100),
            ])
            ->assertCreated();

        $files = Storage::disk('public')->allFiles();
        $this->assertCount(1, $files);

        $this->assertStringStartsWith(
            "profile-images/{$user->organization_id}/",
            $files[0],
        );
        $this->assertStringNotContainsString('evil', $files[0]);
        $this->assertStringNotContainsString('..', $files[0]);
        $this->assertStringEndsWith('.png', $files[0]);

        $this->assertStringContainsString(
            basename($files[0]),
            (string) $response->json('data.url'),
        );
    }

    #[Test]
    public function uploads_are_foldered_by_organisation(): void
    {
        $mine = User::factory()->create();
        $theirs = User::factory()->create();

        $this->actingAs($mine)
            ->postJson('/api/uploads', [
                'file' => UploadedFile::fake()->image('a.png', 100, 100),
            ])
            ->assertCreated();

        $this->forgetAuthentication();

        $this->actingAs($theirs)
            ->postJson('/api/uploads', [
                'file' => UploadedFile::fake()->image('b.png', 100, 100),
            ])
            ->assertCreated();

        $files = Storage::disk('public')->allFiles();
        $this->assertCount(2, $files);
        $this->assertNotSame(
            dirname($files[0]),
            dirname($files[1]),
            'two organisations shared an upload folder',
        );
    }

    #[Test]
    public function uploading_requires_authentication(): void
    {
        $this->postJson('/api/uploads', [
            'file' => UploadedFile::fake()->image('dish.png', 100, 100),
        ])->assertUnauthorized();

        $this->assertCount(0, Storage::disk('public')->allFiles());
    }

    #[Test]
    public function an_uploaded_url_is_accepted_by_the_profile_validator(): void
    {
        $user = User::factory()->create();

        $url = $this->actingAs($user)
            ->postJson('/api/uploads', [
                'file' => UploadedFile::fake()->image('dish.png', 400, 300),
            ])
            ->json('data.url');

        $this->forgetAuthentication();

        $this->actingAs($user)
            ->putJson('/api/profiles/restaurant', [
                'data' => [
                    'businessName' => 'Sams Pizza',
                    'menuGroups' => [[
                        'id' => 'grp_pizza',
                        'name' => 'Pizza',
                        'entries' => [[
                            'id' => 'itm_marg',
                            'title' => 'Margherita',
                            'imageUrl' => $url,
                        ]],
                    ]],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.data.menuGroups.0.entries.0.imageUrl', $url);
    }
}
