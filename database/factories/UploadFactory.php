<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\Upload;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class UploadFactory extends Factory
{
    protected $model = Upload::class;

    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'path' => 'profile-images/'.strtolower((string) Str::ulid()).'.jpg',
            'name' => 'photo.jpg',
            'mime' => 'image/jpeg',
            'bytes' => 128_000,
        ];
    }

    public function inOrganization(Organization $organization): static
    {
        return $this->state(fn (): array => [
            'organization_id' => $organization->id,
            'path' => "profile-images/{$organization->id}/".strtolower((string) Str::ulid()).'.jpg',
        ]);
    }
}
