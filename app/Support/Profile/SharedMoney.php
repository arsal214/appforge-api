<?php

namespace App\Support\Profile;

use App\Models\App;
use App\Models\BusinessProfile;

/**
 * A business's money settings (its currency and its tax) are the settings of
 * every app made from it, whichever side they were changed on: the business
 * details, or one app's settings. Each linked app's draft takes the change.
 * A draft that moves on is told to a builder open on it, not overwritten.
 * Published versions are left alone until the next publish.
 */
class SharedMoney
{
    /** Named the same in a business's details and in an app's `money`. */
    public const KEYS = ['currencySymbol', 'taxRate', 'taxLabel', 'taxIncluded', 'taxOn'];

    /**
     * The money settings as they are kept: blanks, a zero rate and a switch
     * that is off are left out, so "not set" has one spelling on both sides.
     *
     * @return array<string, mixed>
     */
    public static function of(mixed $source): array
    {
        $source = is_array($source) ? $source : [];
        $money = [];

        foreach (['currencySymbol', 'taxLabel'] as $key) {
            if (is_string($source[$key] ?? null) && trim($source[$key]) !== '') {
                $money[$key] = trim($source[$key]);
            }
        }
        if ((is_int($source['taxRate'] ?? null) || is_float($source['taxRate'] ?? null)) && $source['taxRate'] > 0) {
            $money['taxRate'] = $source['taxRate'];
        }
        if (($source['taxIncluded'] ?? null) === true) {
            $money['taxIncluded'] = true;
        }
        if (($source['taxOn'] ?? null) === 'products') {
            $money['taxOn'] = 'products';
        }

        return $money;
    }

    /**
     * What changed between two sets of money settings: each changed key with
     * its new value, or null where it was taken away. Only a change is passed
     * on, so a business that never set a tax cannot wipe out the tax an app
     * set for itself.
     *
     * @param  array<string, mixed>  $was
     * @param  array<string, mixed>  $now
     * @return array<string, mixed>
     */
    public static function changes(array $was, array $now): array
    {
        $changes = [];
        foreach (self::KEYS as $key) {
            if (($was[$key] ?? null) !== ($now[$key] ?? null)) {
                $changes[$key] = $now[$key] ?? null;
            }
        }

        return $changes;
    }

    /**
     * @param  array<string, mixed>  $target
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    private static function apply(array $target, array $changes): array
    {
        foreach ($changes as $key => $value) {
            if ($value === null) {
                unset($target[$key]);
            } else {
                $target[$key] = $value;
            }
        }

        return $target;
    }

    /**
     * From the business details to every app made from it.
     *
     * @param  array<string, mixed>  $changes
     */
    public static function toApps(BusinessProfile $profile, array $changes, ?App $except = null): void
    {
        if ($changes === []) {
            return;
        }

        foreach ($profile->apps()->with('config')->get() as $app) {
            $draft = $app->config;
            if ($draft === null || ($except !== null && $app->is($except))) {
                continue;
            }

            $config = $draft->config;
            $was = is_array($config['money'] ?? null) ? $config['money'] : [];
            $money = self::apply($was, $changes);
            if ($money == $was) {
                continue;
            }

            $config['money'] = $money;
            if ($money === []) {
                unset($config['money']);
            }

            $draft->update(['config' => $config]);
        }
    }

    /**
     * From one app's settings back to its business, and on to the business's
     * other apps. The app saving is left out: its draft is the one being
     * written, and touching it again would answer its own save with a 409.
     *
     * @param  array<string, mixed>|null  $before  the draft as it was
     * @param  array<string, mixed>  $after  the draft being saved
     */
    public static function fromApp(App $app, ?array $before, array $after): void
    {
        $profile = $app->businessProfile;
        if ($profile === null) {
            return;
        }

        $changes = self::changes(self::of($before['money'] ?? null), self::of($after['money'] ?? null));
        if ($changes === []) {
            return;
        }

        $data = is_array($profile->data) ? $profile->data : [];
        $profile->update(['data' => self::apply($data, $changes)]);

        self::toApps($profile, $changes, $app);
    }
}
