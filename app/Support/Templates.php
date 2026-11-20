<?php

namespace App\Support;

final class Templates
{
    public const ALL = [
        [
            'id' => 'tpl_ecommerce',
            'name' => 'Online store',
            'industry' => 'ecommerce',
            'tagline' => 'Products, basket and orders',
            'description' => 'For shops. Products with search, sizes and options, a basket, and orders sent straight to you. Customers can sign in to see what they ordered before.',
            'accentColor' => '#0f766e',
        ],
        [
            'id' => 'tpl_restaurant',
            'name' => 'Restaurant',
            'industry' => 'restaurant',
            'tagline' => 'Menu, ordering and loyalty',
            'description' => 'For takeaway and delivery. Your menu with sizes and extras, a basket with delivery or pickup, a loyalty card, and orders sent straight to you.',
            'accentColor' => '#b45309',
        ],
        [
            'id' => 'tpl_services',
            'name' => 'Appointments',
            'industry' => 'services',
            'tagline' => 'Services and booking requests',
            'description' => 'For gyms, salons and clinics. Your services and prices, opening hours, and a booking request customers send in a few taps. You confirm each one yourself.',
            'accentColor' => '#4f46e5',
        ],
    ];

    public static function find(string $id): ?array
    {
        foreach (self::ALL as $template) {
            if ($template['id'] === $id) {
                return $template;
            }
        }

        return null;
    }

    public static function ids(): array
    {
        return array_column(self::ALL, 'id');
    }
}
