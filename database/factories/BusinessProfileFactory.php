<?php

namespace Database\Factories;

use App\Models\BusinessProfile;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

class BusinessProfileFactory extends Factory
{
    protected $model = BusinessProfile::class;

    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'category' => 'restaurant',
            'data' => [
                'businessName' => fake()->company(),
            ],
        ];
    }

    public function restaurant(): static
    {
        return $this->state(fn () => [
            'category' => 'restaurant',
            'data' => [
                'businessName' => 'Sams Pizza',
                'tagline' => 'Fresh pizza, fast',
                'phone' => '+44 20 7123 4567',
                'currencySymbol' => '£',
                'openingHours' => [
                    ['day' => 'Mon-Fri', 'hours' => '11:00-23:00', 'closed' => false],
                    ['day' => 'Sunday', 'hours' => '', 'closed' => true],
                ],
                'menuGroups' => [
                    [
                        'id' => 'grp_pizza',
                        'name' => 'Pizza',
                        'entries' => [
                            [
                                'id' => 'itm_margherita',
                                'title' => 'Margherita',
                                'description' => 'Tomato, mozzarella, basil',
                                'price' => '£8.50',
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    }

    public function gym(): static
    {
        return $this->state(fn () => [
            'category' => 'gym',
            'data' => [
                'businessName' => 'Iron Works',
                'serviceGroups' => [
                    [
                        'id' => 'grp_memberships',
                        'name' => 'Memberships',
                        'entries' => [
                            ['id' => 'itm_monthly', 'title' => 'Monthly', 'price' => '£35'],
                        ],
                    ],
                ],
                'classTimetable' => [
                    ['day' => 'Monday', 'time' => '18:00', 'name' => 'Spin'],
                ],
            ],
        ]);
    }
}
