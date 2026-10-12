<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CorsTest extends TestCase
{
    private function methodsInUse(): array
    {
        $methods = [];

        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/')) {
                continue;
            }
            foreach ($route->methods() as $method) {
                if ($method === 'HEAD') {
                    continue;
                }
                $methods[$method] = true;
            }
        }

        return array_keys($methods);
    }

    #[Test]
    public function every_method_the_api_uses_is_allowed_by_cors(): void
    {
        $allowed = config('cors.allowed_methods');

        foreach ($this->methodsInUse() as $method) {
            $this->assertContains(
                $method,
                $allowed,
                "{$method} is registered on an api route but missing from cors.allowed_methods — the browser will block it before sending, and the panel will report it as a connection failure",
            );
        }
    }

    #[Test]
    public function the_allowlist_has_nothing_the_api_does_not_use(): void
    {
        $inUse = $this->methodsInUse();

        foreach (config('cors.allowed_methods') as $method) {
            if ($method === 'OPTIONS') {
                continue;
            }
            $this->assertContains(
                $method,
                $inUse,
                "cors.allowed_methods permits {$method}, which no api route uses — allow what is needed, nothing more",
            );
        }
    }

    #[Test]
    public function a_put_preflight_from_the_panel_is_allowed(): void
    {
        $response = $this->call('OPTIONS', '/api/profiles/restaurant', [], [], [], [
            'HTTP_ORIGIN' => config('app.frontend_url', 'http://localhost:3000'),
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'PUT',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'content-type,authorization',
        ]);

        $response->assertNoContent();
        $this->assertStringContainsString(
            'PUT',
            (string) $response->headers->get('Access-Control-Allow-Methods'),
        );
    }

    #[Test]
    public function the_origin_allowlist_is_never_a_wildcard(): void
    {
        $this->assertNotContains('*', config('cors.allowed_origins'));
        $this->assertNotEmpty(config('cors.allowed_origins'));
    }
}
