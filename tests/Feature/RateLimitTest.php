<?php

namespace Tests\Feature;

use App\Models\App as ClientApp;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RateLimitTest extends TestCase
{
    use RefreshDatabase;

    private function limits(string $name, Request $request): array
    {
        $resolved = (RateLimiter::limiter($name))($request);
        $limits = is_array($resolved) ? $resolved : [$resolved];

        $out = [];
        foreach ($limits as $limit) {
            $out[(string) $limit->key] = $limit->maxAttempts;
        }

        return $out;
    }

    private function asUser(User $user): Request
    {
        $request = Request::create('/api/apps', 'GET');
        $request->setUserResolver(fn () => $user);

        return $request;
    }

    #[Test]
    public function the_panel_is_limited_per_person_and_per_organisation(): void
    {
        $user = User::factory()->create();

        $keys = array_keys($this->limits('panel', $this->asUser($user)));

        $this->assertContains("panel:user:{$user->id}", $keys);
        $this->assertContains("panel:org:{$user->organization_id}", $keys);
    }

    #[Test]
    public function two_people_in_one_organisation_have_their_own_allowance(): void
    {
        $organization = Organization::factory()->create();
        $first = User::factory()->for($organization)->create();
        $second = User::factory()->for($organization)->create();

        $mine = array_keys($this->limits('panel', $this->asUser($first)));
        $theirs = array_keys($this->limits('panel', $this->asUser($second)));

        $this->assertNotEquals($mine[0], $theirs[0]);

        $this->assertSame($mine[1], $theirs[1]);
    }

    #[Test]
    public function two_organisations_never_share_a_bucket(): void
    {
        $mine = User::factory()->create();
        $theirs = User::factory()->create();

        $this->assertNotEquals(
            array_keys($this->limits('panel', $this->asUser($mine))),
            array_keys($this->limits('panel', $this->asUser($theirs))),
        );
    }

    #[Test]
    public function the_allowance_is_far_above_what_the_panel_actually_sends(): void
    {
        $user = User::factory()->create();
        $limits = $this->limits('panel', $this->asUser($user));

        $this->assertGreaterThanOrEqual(240, $limits["panel:user:{$user->id}"]);
        $this->assertGreaterThanOrEqual(
            800,
            $limits["panel:org:{$user->organization_id}"],
        );
    }

    #[Test]
    public function an_unauthenticated_request_does_not_break_the_limiter(): void
    {
        $limits = $this->limits('panel', Request::create('/api/apps', 'GET'));

        $this->assertNotEmpty($limits);
    }

    #[Test]
    public function every_authenticated_route_is_now_limited(): void
    {
        $unlimited = [];

        foreach (Route::getRoutes() as $route) {
            $middleware = $route->gatherMiddleware();

            if (! in_array('auth:sanctum', $middleware, true)) {
                continue;
            }

            $throttled = array_filter(
                $middleware,
                fn ($name) => is_string($name) && str_starts_with($name, 'throttle:'),
            );

            if ($throttled === []) {
                $unlimited[] = $route->methods()[0].' '.$route->uri();
            }
        }

        $this->assertSame([], $unlimited, 'these routes have no rate limit');
    }

    #[Test]
    public function a_public_route_is_keyed_by_the_app_it_belongs_to(): void
    {
        $first = ClientApp::factory()->create();
        $second = ClientApp::factory()->create();

        $keyFor = function (ClientApp $app): string {
            $request = Request::create("/api/public/apps/{$app->id}/orders", 'POST');
            Route::dispatchToRoute($request);

            return array_key_first($this->limits('public-orders', $request));
        };

        $this->assertNotEquals($keyFor($first), $keyFor($second));
    }

    #[Test]
    public function one_apps_orders_cannot_exhaust_anothers(): void
    {
        $mine = ClientApp::factory()->create();
        $theirs = ClientApp::factory()->create();

        $order = [
            'lines' => [],
            'total' => null,
            'details' => [['label' => 'Name', 'value' => 'Ayesha']],
        ];

        for ($i = 0; $i < 20; $i++) {
            $this->postJson("/api/public/apps/{$mine->id}/orders", $order)
                ->assertCreated();
        }

        $this->postJson("/api/public/apps/{$mine->id}/orders", $order)
            ->assertStatus(429);

        $this->postJson("/api/public/apps/{$theirs->id}/orders", $order)
            ->assertCreated();
    }
}
