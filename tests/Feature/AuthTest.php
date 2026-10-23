<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function registering_creates_an_organisation_and_its_first_user(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'Sam Okafor',
            'organizationName' => "Sam's Pizza",
            'email' => 'Sam@Example.com',
            'password' => 'correct horse battery',
        ])->assertCreated();

        $response->assertJsonStructure([
            'data' => [
                'token',
                'user' => ['id', 'name', 'email', 'organizationId', 'createdAt'],
                'organization' => ['id', 'name', 'slug', 'createdAt'],
            ],
        ]);

        $this->assertSame('sam@example.com', $response->json('data.user.email'));
        $this->assertSame(
            $response->json('data.organization.id'),
            $response->json('data.user.organizationId'),
        );

        $this->assertDatabaseHas('organizations', ['name' => "Sam's Pizza", 'slug' => 'sams-pizza']);
    }

    #[Test]
    public function the_password_is_never_returned_or_stored_in_the_clear(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'Sam Okafor',
            'organizationName' => "Sam's Pizza",
            'email' => 'sam@example.com',
            'password' => 'correct horse battery',
        ])->assertCreated();

        $this->assertArrayNotHasKey('password', $response->json('data.user'));
        $this->assertNotSame('correct horse battery', User::first()->password);
    }

    #[Test]
    public function registering_a_duplicate_email_is_a_422_on_the_email_field(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->postJson('/api/auth/register', [
            'name' => 'Sam Okafor',
            'organizationName' => "Sam's Pizza",
            'email' => 'taken@example.com',
            'password' => 'correct horse battery',
        ])
            ->assertStatus(422)

            ->assertJsonValidationErrors(['email']);
    }

    #[Test]
    public function registration_validation_errors_are_keyed_in_camel_case(): void
    {
        $this->postJson('/api/auth/register', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'organizationName', 'email', 'password']);
    }

    #[Test]
    public function a_failed_registration_creates_no_organisation(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);
        $before = Organization::count();

        $this->postJson('/api/auth/register', [
            'name' => 'Sam Okafor',
            'organizationName' => 'Orphan Co',
            'email' => 'taken@example.com',
            'password' => 'correct horse battery',
        ])->assertStatus(422);

        $this->assertSame($before, Organization::count());
    }

    #[Test]
    public function logging_in_returns_a_usable_token(): void
    {
        $user = User::factory()->create([
            'email' => 'sam@example.com',
            'password' => 'correct horse battery',
        ]);

        $token = $this->postJson('/api/auth/login', [
            'email' => 'sam@example.com',
            'password' => 'correct horse battery',
        ])->assertOk()->json('data.token');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.user.id', $user->id);
    }

    #[Test]
    public function a_wrong_password_and_an_unknown_account_are_indistinguishable(): void
    {
        User::factory()->create([
            'email' => 'sam@example.com',
            'password' => 'correct horse battery',
        ]);

        $wrongPassword = $this->postJson('/api/auth/login', [
            'email' => 'sam@example.com',
            'password' => 'wrong',
        ])->assertStatus(422);

        $noSuchUser = $this->postJson('/api/auth/login', [
            'email' => 'nobody@example.com',
            'password' => 'wrong',
        ])->assertStatus(422);

        $this->assertSame($wrongPassword->json('errors'), $noSuchUser->json('errors'));
    }

    #[Test]
    public function logging_out_revokes_only_the_token_that_was_used(): void
    {
        $user = User::factory()->create(['password' => 'correct horse battery']);
        $laptop = $user->createToken('laptop')->plainTextToken;
        $phone = $user->createToken('phone')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$laptop}")
            ->postJson('/api/auth/logout')
            ->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 1);

        $this->forgetAuthentication();

        $this->withHeader('Authorization', "Bearer {$laptop}")
            ->getJson('/api/auth/me')
            ->assertUnauthorized();

        $this->forgetAuthentication();

        $this->withHeader('Authorization', "Bearer {$phone}")
            ->getJson('/api/auth/me')
            ->assertOk();
    }

    #[Test]
    public function repeated_failed_logins_are_throttled_per_account(): void
    {
        User::factory()->create([
            'email' => 'sam@example.com',
            'password' => 'correct horse battery',
        ]);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/auth/login', [
                'email' => 'sam@example.com',
                'password' => 'wrong',
            ])->assertStatus(422);
        }

        $this->postJson('/api/auth/login', [
            'email' => 'sam@example.com',
            'password' => 'wrong',
        ])->assertStatus(429);
    }
}
