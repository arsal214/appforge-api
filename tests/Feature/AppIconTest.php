<?php

namespace Tests\Feature;

use App\Models\App as ClientApp;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AppIconTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private ClientApp $shop;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');

        $this->owner = User::factory()->create();
        $this->shop = ClientApp::factory()->create([
            'organization_id' => $this->owner->organization_id,
            'name' => 'Sams Pizza',
        ]);
    }

    /** The address and id of an image uploaded by `$user`, `$width` by `$height`. */
    private function uploaded(int $width, int $height, ?User $user = null): array
    {
        return $this->actingAs($user ?? $this->owner)
            ->postJson('/api/uploads', [
                'file' => UploadedFile::fake()->image('icon.png', $width, $height),
            ])
            ->assertCreated()
            ->json('data');
    }

    private function setIcon(?string $url)
    {
        return $this->actingAs($this->owner)
            ->patchJson("/api/apps/{$this->shop->id}", ['iconUrl' => $url]);
    }

    #[Test]
    public function an_owner_gives_their_app_its_own_icon_and_can_take_it_off_again(): void
    {
        $icon = $this->uploaded(1024, 1024);

        $this->setIcon($icon['url'])
            ->assertOk()
            ->assertJsonPath('data.iconUrl', $icon['url']);

        // Kept as a path, so it survives a change of address.
        $this->assertStringStartsWith('/storage/', $this->shop->fresh()->icon_path);

        $this->actingAs($this->owner)
            ->getJson('/api/apps')
            ->assertOk()
            ->assertJsonPath('data.0.iconUrl', $icon['url']);

        $this->setIcon(null)->assertOk()->assertJsonPath('data.iconUrl', null);
        $this->assertNull($this->shop->fresh()->icon_path);
    }

    #[Test]
    public function an_icon_has_to_be_square_and_big_enough_for_the_app_store(): void
    {
        $this->setIcon($this->uploaded(1024, 800)['url'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('iconUrl');

        $this->setIcon($this->uploaded(512, 512)['url'])
            ->assertStatus(422)
            ->assertJsonPath('errors.iconUrl.0', fn (string $message): bool => str_contains($message, '1024'));

        $this->assertNull($this->shop->fresh()->icon_path);
    }

    #[Test]
    public function a_link_to_somewhere_else_or_to_another_business_is_not_an_icon(): void
    {
        $this->setIcon('https://elsewhere.example/icon.png')
            ->assertStatus(422)
            ->assertJsonValidationErrors('iconUrl');

        $theirs = $this->uploaded(1024, 1024, User::factory()->create());

        $this->setIcon($theirs['url'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('iconUrl');

        $this->assertNull($this->shop->fresh()->icon_path);
    }

    #[Test]
    public function an_image_in_use_as_an_icon_cannot_be_deleted_from_the_library(): void
    {
        $icon = $this->uploaded(1024, 1024);
        $this->setIcon($icon['url'])->assertOk();

        $this->actingAs($this->owner)
            ->deleteJson("/api/uploads/{$icon['upload']['id']}")
            ->assertStatus(409)
            ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'Sams Pizza'));

        $this->setIcon(null)->assertOk();

        $this->actingAs($this->owner)
            ->deleteJson("/api/uploads/{$icon['upload']['id']}")
            ->assertSuccessful();
    }

    #[Test]
    public function changing_something_else_leaves_the_icon_alone(): void
    {
        $icon = $this->uploaded(1024, 1024);
        $this->setIcon($icon['url'])->assertOk();

        $this->actingAs($this->owner)
            ->patchJson("/api/apps/{$this->shop->id}", ['name' => 'Sams Pizzeria'])
            ->assertOk()
            ->assertJsonPath('data.iconUrl', $icon['url']);
    }

    #[Test]
    public function an_owner_picks_the_colour_their_app_opens_on(): void
    {
        $this->actingAs($this->owner)
            ->getJson("/api/apps/{$this->shop->id}")
            ->assertOk()
            ->assertJsonPath('data.splashColor', null);

        $this->actingAs($this->owner)
            ->patchJson("/api/apps/{$this->shop->id}", ['splashColor' => '#B45309'])
            ->assertOk()
            ->assertJsonPath('data.splashColor', '#b45309');

        $this->actingAs($this->owner)
            ->patchJson("/api/apps/{$this->shop->id}", ['splashColor' => 'orange'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('splashColor');

        // Taking it off goes back to the app's own background.
        $this->actingAs($this->owner)
            ->patchJson("/api/apps/{$this->shop->id}", ['splashColor' => null])
            ->assertOk()
            ->assertJsonPath('data.splashColor', null);
    }
}
