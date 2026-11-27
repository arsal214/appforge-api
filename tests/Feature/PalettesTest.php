<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PalettesTest extends TestCase
{
    use RefreshDatabase;

    private function palette(array $overrides = []): array
    {
        return array_merge([
            'id' => 'pal_1',
            'name' => 'Harbour',
            'accentColor' => '#0E7490',
            'backgroundColor' => '#ffffff',
            'surfaceColor' => '#f1f5f9',
            'textColor' => '#0f172a',
            'mutedTextColor' => '#475569',
            'borderColor' => '#e2e8f0',
        ], $overrides);
    }

    #[Test]
    public function a_business_keeps_its_own_palettes_and_only_it_sees_them(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();

        $this->actingAs($owner)->getJson('/api/palettes')->assertOk()->assertExactJson(['data' => []]);

        $this->actingAs($owner)
            ->putJson('/api/palettes', ['palettes' => [$this->palette(['name' => '  Harbour  '])]])
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Harbour')
            ->assertJsonPath('data.0.accentColor', '#0e7490');

        $this->actingAs($owner)->getJson('/api/palettes')->assertJsonCount(1, 'data');
        $this->actingAs($stranger)->getJson('/api/palettes')->assertExactJson(['data' => []]);

        $this->actingAs($owner)->putJson('/api/palettes', ['palettes' => []])->assertOk();
        $this->actingAs($owner)->getJson('/api/palettes')->assertExactJson(['data' => []]);
    }

    #[Test]
    public function a_palette_is_six_colours_and_nothing_else(): void
    {
        $user = User::factory()->create();
        $put = fn (array $palettes) => $this->actingAs($user)->putJson('/api/palettes', ['palettes' => $palettes]);

        $put([$this->palette(['accentColor' => 'red'])])->assertJsonValidationErrors('palettes.0.accentColor');
        $put([$this->palette(['textColor' => null])])->assertJsonValidationErrors('palettes.0.textColor');
        $put([$this->palette(['script' => 'x'])])->assertJsonValidationErrors('palettes.0');
        $put([$this->palette(), $this->palette()])->assertJsonValidationErrors('palettes.1.id');
        $put(array_map(fn ($i) => $this->palette(['id' => "pal_{$i}"]), range(1, 13)))
            ->assertJsonValidationErrors('palettes');
    }

    #[Test]
    public function only_someone_who_builds_can_change_them(): void
    {
        $viewer = User::factory()->create(['role' => 'staff']);

        $this->actingAs($viewer)->getJson('/api/palettes')->assertOk();
        $this->actingAs($viewer)->putJson('/api/palettes', ['palettes' => [$this->palette()]])->assertForbidden();
    }
}
