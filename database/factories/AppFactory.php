<?php

namespace Database\Factories;

use App\Models\App;
use App\Models\Organization;
use App\Support\Templates;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class AppFactory extends Factory
{
    protected $model = App::class;

    public function definition(): array
    {
        $template = fake()->randomElement(Templates::ALL);
        $name = fake()->unique()->company().' App';

        return [
            'organization_id' => Organization::factory(),
            'name' => $name,
            'slug' => Str::slug($name),
            'source_url' => 'https://'.fake()->domainName(),
            'template_id' => $template['id'],
            'industry' => $template['industry'],
            'status' => 'draft',
            'accent_color' => $template['accentColor'],
            'published_version' => null,
        ];
    }

    public function live(): static
    {
        return $this->state(fn () => [
            'status' => 'live',
            'published_version' => 1,
        ]);
    }
}
