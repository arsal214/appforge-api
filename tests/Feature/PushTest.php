<?php

namespace Tests\Feature;

use App\Models\App;
use App\Models\AppDevice;
use App\Models\Organization;
use App\Models\PushCampaign;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PushTest extends TestCase
{
    use RefreshDatabase;

    private function ownApp(User $user): App
    {
        return App::factory()->create(['organization_id' => $user->organization_id]);
    }

    #[Test]
    public function a_phone_can_register_without_authentication(): void
    {
        $app = App::factory()->for(Organization::factory())->create();

        $this->postJson("/api/public/apps/{$app->id}/devices", [
            'token' => str_repeat('a', 64),
            'platform' => 'ios',
        ])->assertNoContent();

        $this->assertDatabaseCount('app_devices', 1);
    }

    #[Test]
    public function registering_the_same_device_twice_updates_one_row(): void
    {

        $app = App::factory()->for(Organization::factory())->create();
        $token = str_repeat('b', 64);

        foreach (['ios', 'ios', 'ios'] as $platform) {
            $this->postJson("/api/public/apps/{$app->id}/devices", [
                'token' => $token,
                'platform' => $platform,
            ])->assertNoContent();
        }

        $this->assertDatabaseCount('app_devices', 1);
    }

    #[Test]
    public function registration_cannot_be_used_to_discover_which_apps_exist(): void
    {
        $real = App::factory()->for(Organization::factory())->create();

        $known = $this->postJson("/api/public/apps/{$real->id}/devices", [
            'token' => str_repeat('c', 64),
            'platform' => 'android',
        ]);
        $invented = $this->postJson('/api/public/apps/app_01jz00000000000000000000/devices', [
            'token' => str_repeat('d', 64),
            'platform' => 'android',
        ]);

        $this->assertSame($known->getStatusCode(), $invented->getStatusCode());
        $this->assertSame($known->getContent(), $invented->getContent());

        $this->assertDatabaseCount('app_devices', 1);
    }

    #[Test]
    public function a_junk_registration_is_rejected(): void
    {
        $app = App::factory()->for(Organization::factory())->create();

        $this->postJson("/api/public/apps/{$app->id}/devices", [
            'token' => 'short',
            'platform' => 'windows',
        ])->assertStatus(422);
    }

    #[Test]
    public function a_campaign_can_be_composed_and_scheduled(): void
    {
        $user = User::factory()->create();
        $app = $this->ownApp($user);

        $this->actingAs($user)->postJson("/api/apps/{$app->id}/push", [
            'title' => 'Two for Tuesday',
            'body' => 'Every Tuesday, all day.',
            'action' => ['type' => 'navigate', 'screenId' => 'scr_home'],
            'scheduledFor' => now()->addDay()->toIso8601String(),
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'scheduled')
            ->assertJsonPath('data.title', 'Two for Tuesday');
    }

    #[Test]
    public function a_notification_cannot_carry_an_action_the_app_does_not_have(): void
    {

        $user = User::factory()->create();
        $app = $this->ownApp($user);

        $this->actingAs($user)->postJson("/api/apps/{$app->id}/push", [
            'title' => 'Hello',
            'body' => 'World',
            'action' => ['type' => 'runScript'],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['action.type']);
    }

    #[Test]
    public function scheduling_in_the_past_is_rejected(): void
    {
        $user = User::factory()->create();
        $app = $this->ownApp($user);

        $this->actingAs($user)->postJson("/api/apps/{$app->id}/push", [
            'title' => 'Hello',
            'body' => 'World',
            'scheduledFor' => now()->subHour()->toIso8601String(),
        ])->assertStatus(422)->assertJsonValidationErrors(['scheduledFor']);
    }

    #[Test]
    public function sending_reports_honestly_that_nothing_was_delivered(): void
    {

        $user = User::factory()->create();
        $app = $this->ownApp($user);
        AppDevice::create([
            'app_id' => $app->id,
            'token' => str_repeat('e', 64),
            'token_hash' => AppDevice::hashToken(str_repeat('e', 64)),
            'platform' => 'ios',
        ]);

        $campaign = PushCampaign::create([
            'app_id' => $app->id,
            'title' => 'Hello',
            'body' => 'World',
            'status' => 'draft',
        ]);

        $this->actingAs($user)
            ->postJson("/api/apps/{$app->id}/push/{$campaign->id}/send")
            ->assertOk()
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.recipientCount', 0);
    }

    #[Test]
    public function a_sent_notification_cannot_be_deleted_or_resent(): void
    {
        $user = User::factory()->create();
        $app = $this->ownApp($user);
        $campaign = PushCampaign::create([
            'app_id' => $app->id,
            'title' => 'Hello',
            'body' => 'World',
            'status' => 'sent',
            'sent_at' => now(),
        ]);

        $this->actingAs($user)
            ->deleteJson("/api/apps/{$app->id}/push/{$campaign->id}")
            ->assertStatus(422);

        $this->actingAs($user)
            ->postJson("/api/apps/{$app->id}/push/{$campaign->id}/send")
            ->assertStatus(422);
    }

    #[Test]
    public function another_organisations_campaigns_are_404(): void
    {
        $user = User::factory()->create();
        $theirs = App::factory()->for(Organization::factory())->create();

        $this->actingAs($user)->getJson("/api/apps/{$theirs->id}/push")->assertNotFound();
        $this->actingAs($user)->getJson("/api/apps/{$theirs->id}/push/audience")->assertNotFound();
        $this->actingAs($user)
            ->postJson("/api/apps/{$theirs->id}/push", ['title' => 'x', 'body' => 'y'])
            ->assertNotFound();
    }

    #[Test]
    public function the_audience_endpoint_says_delivery_is_not_configured(): void
    {

        $user = User::factory()->create();
        $app = $this->ownApp($user);

        $this->actingAs($user)
            ->getJson("/api/apps/{$app->id}/push/audience")
            ->assertOk()
            ->assertJsonPath('data.canDeliver', false)
            ->assertJsonPath('data.total', 0);
    }

    #[Test]
    public function device_tokens_are_never_returned_by_any_endpoint(): void
    {
        $user = User::factory()->create();
        $app = $this->ownApp($user);
        $token = str_repeat('f', 64);
        AppDevice::create([
            'app_id' => $app->id,
            'token' => $token,
            'token_hash' => AppDevice::hashToken($token),
            'platform' => 'ios',
        ]);

        $audience = $this->actingAs($user)->getJson("/api/apps/{$app->id}/push/audience");
        $campaigns = $this->actingAs($user)->getJson("/api/apps/{$app->id}/push");

        $this->assertStringNotContainsString($token, $audience->getContent());
        $this->assertStringNotContainsString($token, $campaigns->getContent());
    }
}
