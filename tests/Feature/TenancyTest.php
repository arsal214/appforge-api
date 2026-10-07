<?php

namespace Tests\Feature;

use App\Models\App;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The tests that matter most in this repo.
 *
 * Everything else here is a field that serialises wrong and gets noticed in a
 * day. A tenancy hole is one customer reading another customer's business, and
 * it is the failure that ends the company. These run against MySQL, not sqlite
 * in memory, so the foreign keys and column types under test are the ones that
 * ship.
 */
class TenancyTest extends TestCase
{
    use RefreshDatabase;

    private function otherOrgApp(): App
    {
        return App::factory()->for(Organization::factory())->create();
    }

    #[Test]
    public function another_organisations_app_is_404_not_403(): void
    {
        $user = User::factory()->create();
        $theirs = $this->otherOrgApp();

        // 403 would confirm the record exists, which tells a competitor that a
        // particular business is our customer. The status code is the leak.
        $this->actingAs($user)
            ->getJson("/api/apps/{$theirs->id}")
            ->assertNotFound();

        $this->actingAs($user)
            ->patchJson("/api/apps/{$theirs->id}", ['name' => 'Hijacked'])
            ->assertNotFound();

        $this->actingAs($user)
            ->deleteJson("/api/apps/{$theirs->id}")
            ->assertNotFound();
    }

    #[Test]
    public function a_failed_cross_tenant_write_changes_nothing(): void
    {
        $user = User::factory()->create();
        $theirs = $this->otherOrgApp();
        $originalName = $theirs->name;

        $this->actingAs($user)
            ->patchJson("/api/apps/{$theirs->id}", ['name' => 'Hijacked'])
            ->assertNotFound();

        $this->assertDatabaseHas('apps', [
            'id' => $theirs->id,
            'name' => $originalName,
            'deleted_at' => null,
        ]);
    }

    #[Test]
    public function the_list_endpoint_returns_only_your_own_apps(): void
    {
        $user = User::factory()->create();
        $mine = App::factory()->count(2)->create([
            'organization_id' => $user->organization_id,
        ]);
        $this->otherOrgApp();

        $response = $this->actingAs($user)->getJson('/api/apps')->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->sort()->values();
        $this->assertEquals($mine->pluck('id')->sort()->values(), $ids);
    }

    #[Test]
    public function a_malformed_app_id_is_404_and_not_a_server_error(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/apps/not-an-id')->assertNotFound();
        $this->actingAs($user)->getJson('/api/apps/usr_01jz00000000000000000000')->assertNotFound();
    }

    #[Test]
    public function the_global_scope_fails_closed_without_an_authenticated_user(): void
    {
        // Belt and braces: the controllers scope through the relationship, so
        // this scope is the second line of defence. If it ever silently stops
        // filtering, a query written later without the relationship would go
        // unscoped — so assert it returns nothing rather than everything.
        App::factory()->count(3)->create();

        $this->assertSame(0, App::query()->count());
    }

    #[Test]
    public function every_app_endpoint_requires_authentication(): void
    {
        $app = $this->otherOrgApp();

        $this->getJson('/api/apps')->assertUnauthorized();
        $this->postJson('/api/apps', [])->assertUnauthorized();
        $this->getJson("/api/apps/{$app->id}")->assertUnauthorized();
        $this->patchJson("/api/apps/{$app->id}", [])->assertUnauthorized();
        $this->deleteJson("/api/apps/{$app->id}")->assertUnauthorized();
        $this->patchJson('/api/account', [])->assertUnauthorized();
        $this->patchJson('/api/organization', [])->assertUnauthorized();
        $this->getJson('/api/auth/me')->assertUnauthorized();
    }
}
