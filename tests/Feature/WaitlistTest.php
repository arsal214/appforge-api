<?php

namespace Tests\Feature;

use App\Models\WaitlistSignup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WaitlistTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function an_early_access_request_is_kept(): void
    {
        $this->postJson('/api/public/waitlist', [
            'websiteUrl' => 'https://thecornerdeli.com',
            'email' => 'sam@thecornerdeli.com',
            'source' => 'https://appforge.test/pricing',
        ])->assertNoContent();

        $signup = WaitlistSignup::sole();

        $this->assertSame('sam@thecornerdeli.com', $signup->email);
        $this->assertSame('https://thecornerdeli.com', $signup->website_url);
        $this->assertSame('https://appforge.test/pricing', $signup->source);
        $this->assertStringStartsWith('wl_', $signup->id);
    }

    #[Test]
    public function asking_twice_corrects_the_lead_instead_of_duplicating_it(): void
    {
        $this->postJson('/api/public/waitlist', [
            'websiteUrl' => 'https://thecornerdeli.co',
            'email' => 'sam@thecornerdeli.com',
            'source' => 'https://appforge.test/pricing',
        ])->assertNoContent();

        $this->postJson('/api/public/waitlist', [
            'websiteUrl' => 'https://thecornerdeli.com',
            'email' => 'Sam@TheCornerDeli.com',
        ])->assertNoContent();

        $this->assertSame(1, WaitlistSignup::count());

        $signup = WaitlistSignup::sole();

        $this->assertSame('https://thecornerdeli.com', $signup->website_url);
        $this->assertSame(
            'https://appforge.test/pricing',
            $signup->source,
            'a repeat ask blanked the source the first one established',
        );
    }

    #[Test]
    public function a_bad_address_is_refused_under_the_key_the_form_uses(): void
    {
        $this->postJson('/api/public/waitlist', [
            'websiteUrl' => 'not a website',
            'email' => 'not an email',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['websiteUrl', 'email']);

        $this->assertSame(0, WaitlistSignup::count());
    }

    #[Test]
    public function the_form_is_not_a_way_to_fill_the_table(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/public/waitlist', [
                'websiteUrl' => "https://shop-{$i}.com",
                'email' => "owner-{$i}@example.com",
            ])->assertNoContent();
        }

        $this->postJson('/api/public/waitlist', [
            'websiteUrl' => 'https://shop-6.com',
            'email' => 'owner-6@example.com',
        ])->assertStatus(429);

        $this->assertSame(5, WaitlistSignup::count());
    }

    #[Test]
    public function a_signup_belongs_to_no_organisation_so_the_panel_cannot_reach_it(): void
    {
        $this->assertFalse(
            Schema::hasColumn('waitlist_signups', 'organization_id'),
            'a waitlist signup has been tied to an organisation',
        );
    }
}
