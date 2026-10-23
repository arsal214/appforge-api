<?php

namespace App\Support;

final class Templates
{
    public const ALL = [
        [
            'id' => 'tpl_ecommerce',
            'name' => 'Online store',
            'industry' => 'ecommerce',
            'tagline' => 'Products, cart and reorder',
            'description' => 'For WooCommerce and Shopify stores. Product browsing, a cart, order history and a one-tap reorder for regulars.',
            'accentColor' => '#0f766e',
        ],
        [
            'id' => 'tpl_restaurant',
            'name' => 'Restaurant',
            'industry' => 'restaurant',
            'tagline' => 'Menu, ordering and loyalty',
            'description' => "For takeaway and delivery. A native menu, reorder from your last basket, a loyalty card and push for the day's special.",
            'accentColor' => '#b45309',
        ],
        [
            'id' => 'tpl_services',
            'name' => 'Appointments',
            'industry' => 'services',
            'tagline' => 'Booking, reminders and rebooking',
            'description' => 'For gyms, salons and clinics. Live availability, reminders with confirm and reschedule, a class timetable and a membership card.',
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
