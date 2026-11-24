<?php

namespace Tests\Feature;

use App\Models\App as ClientApp;
use App\Models\User;
use App\Support\BundleId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AppBundleIdTest extends TestCase
{
    use RefreshDatabase;

    private function make(User $user, string $name): array
    {
        return $this->actingAs($user)
            ->postJson('/api/apps', ['name' => $name, 'templateId' => 'tpl_restaurant', 'sourceUrl' => null])
            ->assertCreated()
            ->json('data');
    }

    #[Test]
    public function every_app_is_made_with_a_store_id_of_its_own(): void
    {
        $sam = User::factory()->create();
        $joe = User::factory()->create();

        $first = $this->make($sam, 'Sams Pizza');
        $this->assertSame('com.samspizza.app', $first['bundleId']);

        // The same name at another business is another app to the stores.
        $second = $this->make($joe, 'Sams Pizza');
        $this->assertSame('com.samspizza2.app', $second['bundleId']);

        // Never the id the shell itself ships with.
        $this->assertNotSame('com.appforge.shell', $first['bundleId']);
    }

    #[Test]
    public function a_name_that_cannot_be_an_id_still_gets_a_valid_one(): void
    {
        $user = User::factory()->create();

        foreach (['3 Brothers', 'مطعم', '!!!'] as $name) {
            $id = ClientApp::factory()->create([
                'organization_id' => $user->organization_id,
                'name' => $name,
            ])->bundle_id;

            $this->assertTrue(BundleId::valid($id), "{$name} was given {$id}");
        }
    }

    #[Test]
    public function an_owner_changes_the_id_to_one_that_is_valid_and_free(): void
    {
        $sam = User::factory()->create();
        $app = $this->make($sam, 'Sams Pizza');
        $other = $this->make(User::factory()->create(), 'Joes Salon');

        $change = fn (string $id) => $this->actingAs($sam)
            ->patchJson("/api/apps/{$app['id']}", ['bundleId' => $id]);

        $change('com.samspizzeria.leeds')
            ->assertOk()
            ->assertJsonPath('data.bundleId', 'com.samspizzeria.leeds');

        foreach (['Sams Pizza', 'com.Sams.app', 'samspizza', 'com.sams-pizza.app', 'com.3sams.app', 'com.appforge.shell', 'com.apple.sams'] as $bad) {
            $change($bad)->assertStatus(422)->assertJsonValidationErrors('bundleId');
        }

        $change('com.appforge.shell')
            ->assertStatus(422)
            ->assertJsonPath('errors.bundleId.0', fn (string $message): bool => str_contains($message, 'belongs to someone else'));

        // Another business's id is taken, and says no more than that.
        $change($other['bundleId'])
            ->assertStatus(422)
            ->assertJsonPath('errors.bundleId.0', 'Another app already uses that ID. Choose a different one.');

        // Saving its own id again is not a clash.
        $change('com.samspizzeria.leeds')->assertOk();
    }

    #[Test]
    public function an_app_on_the_stores_keeps_its_id(): void
    {
        $user = User::factory()->create();
        $app = ClientApp::factory()->live()->create(['organization_id' => $user->organization_id]);

        $this->actingAs($user)
            ->patchJson("/api/apps/{$app->id}", ['bundleId' => 'com.somethingelse.app'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('bundleId');

        $this->actingAs($user)
            ->patchJson("/api/apps/{$app->id}", ['name' => 'A New Name'])
            ->assertOk()
            ->assertJsonPath('data.bundleId', $app->bundle_id);
    }
}
