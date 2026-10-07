<?php

namespace App\Support;

/**
 * The starting points a customer can pick from.
 *
 * These are code, not rows. Each `industry` keys into `templateConfigs` in
 * `@appforge/components`, so a template only exists if the renderer can render
 * it. Put them in a database table and you gain the ability to create a
 * template the shell has never heard of — a config that renders as a blank
 * screen on a customer's phone. The trade is that adding a template is a
 * deploy, which is correct: it *is* a deploy, because the components ship with
 * it.
 *
 * These three are the plan's rank 1-3 industries.
 */
final class Templates
{
    /** @var list<array{id: string, name: string, industry: string, tagline: string, description: string, accentColor: string}> */
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

    /** @return array{id: string, name: string, industry: string, tagline: string, description: string, accentColor: string}|null */
    public static function find(string $id): ?array
    {
        foreach (self::ALL as $template) {
            if ($template['id'] === $id) {
                return $template;
            }
        }

        return null;
    }

    /** @return list<string> */
    public static function ids(): array
    {
        return array_column(self::ALL, 'id');
    }
}
