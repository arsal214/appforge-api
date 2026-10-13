<?php

namespace App\Support\Config;

use AppForge\Schema\Contract;

/**
 * Validates an SDUI config before it is stored or published.
 *
 * Why this is strict to the point of pedantry:
 *
 *  - **Unknown keys are rejected everywhere.** This is the single rule that
 *    keeps credentials out of configs. A config is served to an app binary
 *    anyone can unpack, so it is effectively public; an allowlist means a
 *    well-meaning future feature cannot quietly park an API key in it, and a
 *    hostile client cannot smuggle one past us into a CDN.
 *  - **Actions are a closed vocabulary.** No expression language, no callback
 *    strings. A runtime that executes arbitrary strings from a server is remote
 *    code execution and an App Store rejection.
 *  - **Every URL is scheme-checked.** `javascript:` in an `openUrl` action is
 *    a stored XSS in the panel preview (which renders via react-native-web in
 *    a real browser) before it is anything else.
 *  - **Everything is bounded.** Size, counts, string lengths. The shell
 *    downloads this over mobile data at every cold start.
 *
 * Errors come back keyed by a dotted path (`screens.0.sections.2.props.title`)
 * so the panel can point at the offending control rather than saying "invalid".
 */
class ConfigValidator
{
    /** @var array<string, list<string>> */
    private array $errors = [];

    /**
     * @param  mixed  $config
     * @return array<string, list<string>> empty when valid
     */
    public function validate($config): array
    {
        $this->errors = [];

        if (! $this->isMap($config)) {
            return $this->fail('', 'The config must be an object.');
        }

        $encoded = json_encode($config);
        if ($encoded === false || strlen($encoded) > Contract::limit('maxBytes')) {
            return $this->fail('', 'That config is too large to publish.');
        }

        // Depth, before anything walks this structure. `[[[[[…` is only two
        // bytes per level, so a config well under the size cap can still be
        // 100,000 levels deep — and PHP has no recursion limit, so the
        // navigation-target walk below would take the process down with it.
        // json_decode's depth argument does the check without recursing.
        json_decode($encoded, true, Contract::limit('maxDepth'));
        if (json_last_error() !== JSON_ERROR_NONE) {
            return $this->fail('', 'That config is nested too deeply.');
        }

        $this->allowOnly($config, '', Contract::fields('config'));

        if (($config['schemaVersion'] ?? null) !== Contract::SCHEMA_VERSION) {
            $this->add('schemaVersion', 'Unsupported schema version.');
        }

        $this->validateShellVersion($config['minShellVersion'] ?? null);
        $this->validateApp($config['app'] ?? null);
        $this->validateTheme($config['theme'] ?? null);

        if (array_key_exists('maintenance', $config) && $config['maintenance'] !== null) {
            $this->validateMaintenance($config['maintenance'], 'maintenance');
        }

        $screenIds = $this->validateScreens($config['screens'] ?? null);
        $this->validateTabs($config['tabs'] ?? null, $screenIds);

        if (array_key_exists('header', $config) && $config['header'] !== null) {
            $this->validateHeader($config['header'], 'header');
        }
        if (array_key_exists('navigation', $config) && $config['navigation'] !== null) {
            $this->validateNavigation($config['navigation'], 'navigation', $screenIds);
        }

        // Navigation targets are checked last, once every screen id is known.
        // Skipped when the screen list itself failed, or every navigate action
        // in the config would report a dangling target and bury the real error.
        if ($screenIds !== []) {
            $this->validateNavigationTargets($config, $screenIds);
        }

        return $this->errors;
    }

    /* --- Top-level pieces --------------------------------------------- */

    private function validateShellVersion(mixed $value): void
    {
        if (! is_string($value) || ! preg_match(Contract::pattern('shellVersion'), $value)) {
            $this->add('minShellVersion', 'Expected a version like 1.0.0.');
        }
    }

    private function validateApp(mixed $app): void
    {
        if (! $this->isMap($app)) {
            $this->add('app', 'Expected an object.');

            return;
        }

        $this->allowOnly($app, 'app', Contract::fields('app'));
        $this->requireText($app['name'] ?? null, 'app.name', Contract::limit('maxShortText'));

        $slug = $app['slug'] ?? null;
        if (! is_string($slug) || ! preg_match(Contract::pattern('slug'), $slug)) {
            $this->add('app.slug', 'Expected a lowercase slug like sams-pizza.');
        }
    }

    private function validateTheme(mixed $theme): void
    {
        if (! $this->isMap($theme)) {
            $this->add('theme', 'Expected an object.');

            return;
        }

        $this->allowOnly($theme, 'theme', Contract::vocabulary('themeKeys'));

        foreach (Contract::vocabulary('themeKeys') as $key) {
            if ($key === 'cornerRadius') {
                continue;
            }
            $this->requireColor($theme[$key] ?? null, "theme.{$key}");
        }

        $radius = $theme['cornerRadius'] ?? null;
        if (! is_int($radius) || $radius < 0 || $radius > 48) {
            $this->add('theme.cornerRadius', 'Expected a whole number between 0 and 48.');
        }
    }

    /**
     * The off-air switch.
     *
     * `enabled` is required and must be a real boolean — not "true", not 1.
     * This is the one field in the contract whose value decides whether a
     * customer's business is reachable at all, and a truthy string quietly
     * meaning "on" is not a coin worth flipping.
     */
    private function validateMaintenance(mixed $maintenance, string $path): void
    {
        if (! $this->isMap($maintenance)) {
            $this->add($path, 'Expected an object.');

            return;
        }

        $this->allowOnly($maintenance, $path, Contract::fields('maintenance'));
        $this->requireBool($maintenance['enabled'] ?? null, "{$path}.enabled");
        $this->optionalText($maintenance, 'title', $path, Contract::limit('maxShortText'));
        $this->optionalText($maintenance, 'message', $path, Contract::limit('maxText'));
    }

    /** The bar across the top of the app. */
    private function validateHeader(mixed $header, string $path): void
    {
        if (! $this->isMap($header)) {
            $this->add($path, 'Expected an object.');

            return;
        }

        $this->allowOnly($header, $path, Contract::fields('header'));
        $this->requireBool($header['show'] ?? null, "{$path}.show");
        $this->optionalText($header, 'title', $path, Contract::limit('maxShortText'));
        $this->optionalUrl($header, 'logoUrl', $path);

        if (array_key_exists('trailingIcon', $header) && $header['trailingIcon'] !== null) {
            if (! in_array($header['trailingIcon'], Contract::vocabulary('tabIcons'), true)) {
                $this->add("{$path}.trailingIcon", 'Unknown icon.');
            }
        }

        $this->optionalAction($header, 'trailingAction', $path);

        // An icon with nothing behind it is a button that does nothing, which is
        // worse than no button. The panel does not let you create one; this is
        // the guard for everything that is not the panel.
        if (
            ! empty($header['trailingIcon'])
            && empty($header['trailingAction'])
        ) {
            $this->add(
                "{$path}.trailingAction",
                'Choose what this button does, or remove the icon.',
            );
        }
    }

    /**
     * Bottom tabs, a side drawer, or both.
     *
     * @param  list<string>  $screenIds
     */
    private function validateNavigation(mixed $navigation, string $path, array $screenIds): void
    {
        if (! $this->isMap($navigation)) {
            $this->add($path, 'Expected an object.');

            return;
        }

        $this->allowOnly($navigation, $path, Contract::fields('navigation'));

        if (! in_array($navigation['mode'] ?? null, Contract::vocabulary('navigationModes'), true)) {
            $this->add("{$path}.mode", "Expected 'tabs', 'drawer' or 'both'.");
        }

        if (! array_key_exists('drawerItems', $navigation) || $navigation['drawerItems'] === null) {
            // Absent means "same as the tabs", which is always valid.
            return;
        }

        $items = $navigation['drawerItems'];
        if (! is_array($items) || ! array_is_list($items)) {
            $this->add("{$path}.drawerItems", 'Expected a list.');

            return;
        }

        if (count($items) > Contract::limit('maxDrawerItems')) {
            $this->add("{$path}.drawerItems", 'Too many menu entries to be useful.');

            return;
        }

        $ids = [];
        foreach ($items as $index => $item) {
            $itemPath = "{$path}.drawerItems.{$index}";

            if (! $this->isMap($item)) {
                $this->add($itemPath, 'Expected an object.');

                continue;
            }

            // Drawer entries are the same shape as tabs on purpose: one editor,
            // one renderer, one thing to get right.
            $this->allowOnly($item, $itemPath, Contract::fields('tab'));
            $id = $this->requireId($item['id'] ?? null, "{$itemPath}.id");
            $this->requireText($item['label'] ?? null, "{$itemPath}.label", Contract::limit('maxLabel'));

            if ($id !== null) {
                if (in_array($id, $ids, true)) {
                    $this->add("{$itemPath}.id", 'Two menu entries share this id.');
                } else {
                    $ids[] = $id;
                }
            }

            if (! in_array($item['icon'] ?? null, Contract::vocabulary('tabIcons'), true)) {
                $this->add("{$itemPath}.icon", 'Unknown icon.');
            }

            $screenId = $item['screenId'] ?? null;
            if (! is_string($screenId) || ! in_array($screenId, $screenIds, true)) {
                $this->add("{$itemPath}.screenId", 'This points at a screen that does not exist.');
            }
        }
    }

    /** @return list<string> the screen ids that validated */
    private function validateScreens(mixed $screens): array
    {
        if (! is_array($screens) || ! array_is_list($screens) || $screens === []) {
            $this->add('screens', 'An app needs at least one screen.');

            return [];
        }

        if (count($screens) > Contract::limit('maxScreens')) {
            $this->add('screens', 'Too many screens.');

            return [];
        }

        $ids = [];
        foreach ($screens as $index => $screen) {
            $path = "screens.{$index}";

            if (! $this->isMap($screen)) {
                $this->add($path, 'Expected an object.');

                continue;
            }

            $this->allowOnly($screen, $path, Contract::fields('screen'));
            $id = $this->requireId($screen['id'] ?? null, "{$path}.id");
            $this->requireText($screen['title'] ?? null, "{$path}.title", Contract::limit('maxShortText'));

            if ($id !== null) {
                if (in_array($id, $ids, true)) {
                    // Duplicates make `findScreen` return the first match and
                    // silently strand everything pointing at the second.
                    $this->add("{$path}.id", 'Two screens share this id.');
                } else {
                    $ids[] = $id;
                }
            }

            $this->validateSections($screen['sections'] ?? null, "{$path}.sections");
        }

        return $ids;
    }

    /** @param  list<string>  $screenIds */
    private function validateTabs(mixed $tabs, array $screenIds): void
    {
        if (! is_array($tabs) || ! array_is_list($tabs) || $tabs === []) {
            $this->add('tabs', 'An app needs at least one tab.');

            return;
        }

        if (count($tabs) > Contract::limit('maxTabs')) {
            $this->add('tabs', 'A tab bar fits at most '.Contract::limit('maxTabs').' tabs.');

            return;
        }

        $ids = [];
        foreach ($tabs as $index => $tab) {
            $path = "tabs.{$index}";

            if (! $this->isMap($tab)) {
                $this->add($path, 'Expected an object.');

                continue;
            }

            $this->allowOnly($tab, $path, Contract::fields('tab'));
            $id = $this->requireId($tab['id'] ?? null, "{$path}.id");
            $this->requireText($tab['label'] ?? null, "{$path}.label", 24);

            if ($id !== null) {
                if (in_array($id, $ids, true)) {
                    $this->add("{$path}.id", 'Two tabs share this id.');
                } else {
                    $ids[] = $id;
                }
            }

            if (! in_array($tab['icon'] ?? null, Contract::vocabulary('tabIcons'), true)) {
                // The shell ships a fixed icon set. A config cannot introduce
                // one, and a tab with no icon is a blank square on a phone.
                $this->add("{$path}.icon", 'Unknown icon.');
            }

            $screenId = $tab['screenId'] ?? null;
            if (! is_string($screenId) || ! in_array($screenId, $screenIds, true)) {
                $this->add("{$path}.screenId", 'This tab points at a screen that does not exist.');
            }
        }
    }

    /* --- Sections ------------------------------------------------------ */

    private function validateSections(mixed $sections, string $path): void
    {
        if (! is_array($sections) || ! array_is_list($sections)) {
            $this->add($path, 'Expected a list of sections.');

            return;
        }

        if (count($sections) > Contract::limit('maxSectionsPerScreen')) {
            $this->add($path, 'Too many sections on one screen.');

            return;
        }

        foreach ($sections as $index => $section) {
            $this->validateSection($section, "{$path}.{$index}");
        }
    }

    private function validateSection(mixed $section, string $path): void
    {
        if (! $this->isMap($section)) {
            $this->add($path, 'Expected an object.');

            return;
        }

        $this->allowOnly($section, $path, Contract::fields('section'));
        $this->requireId($section['id'] ?? null, "{$path}.id");

        $type = $section['type'] ?? null;
        if (! is_string($type) || ! in_array($type, Contract::vocabulary('sectionTypes'), true)) {
            $this->add("{$path}.type", 'Unknown section type.');

            return;
        }

        if (array_key_exists('style', $section) && $section['style'] !== null) {
            $this->validateSectionStyle($section['style'], "{$path}.style");
        }

        $props = $section['props'] ?? null;
        if (! $this->isMap($props)) {
            $this->add("{$path}.props", 'Expected an object.');

            return;
        }

        $this->validateProps($type, $props, "{$path}.props");
    }

    /**
     * Per-section style. One branch for all twelve section types, because the
     * contract gives them one shared `style` object rather than style keys
     * grafted onto each `props`.
     *
     * Typography is keyed by **role** — title, subtitle, body, label — because
     * a block's heading and its body are deliberately different sizes, and one
     * font size for the whole block collapses that hierarchy.
     *
     * Every bound here has a reason a customer would recognise. Font size is
     * capped because a config must not be able to produce a screen its own
     * customers cannot read; padding is capped because a section that pushes
     * the rest of a screen out of reach is a broken app, not a bold design.
     */
    private function validateSectionStyle(mixed $style, string $path): void
    {
        if (! $this->isMap($style)) {
            $this->add($path, 'Expected an object.');

            return;
        }

        $this->allowOnly($style, $path, Contract::fields('sectionStyle'));

        if (array_key_exists('text', $style) && $style['text'] !== null) {
            $this->validateTextStyles($style['text'], "{$path}.text");
        }

        foreach ([
            'backgroundColor',
            'borderColor',
            'accentColor',
            'buttonColor',
            'buttonTextColor',
        ] as $key) {
            $this->optionalColor($style, $key, $path);
        }

        $this->optionalWholeNumber($style, 'borderWidth', $path, 0, Contract::limit('maxBorderWidth'));
        $this->optionalWholeNumber($style, 'cornerRadius', $path, 0, Contract::limit('maxCornerRadius'));
        $this->optionalWholeNumber($style, 'paddingX', $path, 0, Contract::limit('maxPadding'));
        $this->optionalWholeNumber($style, 'paddingY', $path, 0, Contract::limit('maxPadding'));
    }

    /** Typography, keyed by the named slot it applies to. */
    private function validateTextStyles(mixed $text, string $path): void
    {
        if (! $this->isMap($text)) {
            $this->add($path, 'Expected an object.');

            return;
        }

        // The keys are role names, so the allowlist is the role vocabulary.
        $this->allowOnly($text, $path, Contract::vocabulary('textRoles'));

        foreach ($text as $role => $spec) {
            $rolePath = "{$path}.{$role}";

            if (! $this->isMap($spec)) {
                $this->add($rolePath, 'Expected an object.');

                continue;
            }

            $this->allowOnly($spec, $rolePath, Contract::fields('textStyle'));

            foreach ([
                'fontFamily' => 'fontFamilies',
                'fontWeight' => 'fontWeights',
                'textTransform' => 'textTransforms',
                'textDecoration' => 'textDecorations',
                'textAlign' => 'textAligns',
            ] as $key => $vocabulary) {
                if (! array_key_exists($key, $spec) || $spec[$key] === null) {
                    continue;
                }
                if (! in_array($spec[$key], Contract::vocabulary($vocabulary), true)) {
                    $this->add("{$rolePath}.{$key}", 'Not an option we support.');
                }
            }

            $this->optionalColor($spec, 'color', $rolePath);
            $this->optionalWholeNumber(
                $spec, 'fontSize', $rolePath,
                Contract::limit('minFontSize'), Contract::limit('maxFontSize'),
            );
        }
    }

    /** @param array<string, mixed> $bag */
    private function optionalWholeNumber(
        array $bag,
        string $key,
        string $path,
        int $min,
        int $max,
    ): void {
        if (! array_key_exists($key, $bag) || $bag[$key] === null) {
            return;
        }

        $value = $bag[$key];
        if (! is_int($value) || $value < $min || $value > $max) {
            $this->add("{$path}.{$key}", "Expected a whole number between {$min} and {$max}.");
        }
    }

    /** @param array<string, mixed> $props */
    private function validateProps(string $type, array $props, string $path): void
    {
        match ($type) {
            'hero' => $this->heroProps($props, $path),
            'category_row' => $this->categoryRowProps($props, $path),
            'product_grid' => $this->productGridProps($props, $path),
            'product_list' => $this->productListProps($props, $path),
            'promo_banner' => $this->promoBannerProps($props, $path),
            'text_block' => $this->textBlockProps($props, $path),
            'image_banner' => $this->imageBannerProps($props, $path),
            'button_row' => $this->buttonRowProps($props, $path),
            'opening_hours' => $this->openingHoursProps($props, $path),
            'loyalty_card' => $this->loyaltyCardProps($props, $path),
            'booking_cta' => $this->bookingCtaProps($props, $path),
            'contact_block' => $this->contactBlockProps($props, $path),
            default => $this->add($path, 'Unknown section type.'),
        };
    }

    /** @param array<string, mixed> $p */
    private function heroProps(array $p, string $path): void
    {
        $this->allowOnly($p, $path, Contract::fields('sectionProps.hero'));
        $this->requireText($p['title'] ?? null, "{$path}.title", Contract::limit('maxShortText'));
        $this->optionalText($p, 'subtitle', $path, Contract::limit('maxShortText'));
        $this->optionalUrl($p, 'imageUrl', $path);
        $this->optionalColor($p, 'backgroundColor', $path);
        $this->optionalText($p, 'ctaLabel', $path, 40);
        $this->optionalAction($p, 'ctaAction', $path);
    }

    /** @param array<string, mixed> $p */
    private function categoryRowProps(array $p, string $path): void
    {
        $this->allowOnly($p, $path, Contract::fields('sectionProps.category_row'));
        $this->optionalText($p, 'title', $path, Contract::limit('maxShortText'));
        $this->validateChips($p['chips'] ?? null, "{$path}.chips", required: true);
    }

    /** @param array<string, mixed> $p */
    private function productGridProps(array $p, string $path): void
    {
        $this->allowOnly($p, $path, Contract::fields('sectionProps.product_grid'));
        $this->optionalText($p, 'title', $path, Contract::limit('maxShortText'));
        if (! in_array($p['columns'] ?? null, [2, 3], true)) {
            $this->add("{$path}.columns", 'Expected 2 or 3.');
        }
        $this->requireBool($p['showPrices'] ?? null, "{$path}.showPrices");
        $this->validateItemSource($p['source'] ?? null, "{$path}.source");
    }

    /** @param array<string, mixed> $p */
    private function productListProps(array $p, string $path): void
    {
        $this->allowOnly($p, $path, Contract::fields('sectionProps.product_list'));
        $this->optionalText($p, 'title', $path, Contract::limit('maxShortText'));
        $this->requireBool($p['showImages'] ?? null, "{$path}.showImages");
        $this->validateItemSource($p['source'] ?? null, "{$path}.source");
    }

    /** @param array<string, mixed> $p */
    private function promoBannerProps(array $p, string $path): void
    {
        $this->allowOnly($p, $path, Contract::fields('sectionProps.promo_banner'));
        $this->requireText($p['headline'] ?? null, "{$path}.headline", Contract::limit('maxShortText'));
        $this->optionalText($p, 'body', $path, Contract::limit('maxText'));
        $this->optionalText($p, 'ctaLabel', $path, 40);
        $this->optionalAction($p, 'ctaAction', $path);
        $this->optionalColor($p, 'backgroundColor', $path);
    }

    /** @param array<string, mixed> $p */
    private function textBlockProps(array $p, string $path): void
    {
        $this->allowOnly($p, $path, Contract::fields('sectionProps.text_block'));
        $this->optionalText($p, 'heading', $path, Contract::limit('maxShortText'));
        $this->requireText($p['body'] ?? null, "{$path}.body", Contract::limit('maxText'));
        if (! in_array($p['align'] ?? null, ['left', 'center'], true)) {
            $this->add("{$path}.align", "Expected 'left' or 'center'.");
        }
    }

    /** @param array<string, mixed> $p */
    private function imageBannerProps(array $p, string $path): void
    {
        $this->allowOnly($p, $path, Contract::fields('sectionProps.image_banner'));
        $this->requireUrl($p['imageUrl'] ?? null, "{$path}.imageUrl");
        $this->optionalText($p, 'caption', $path, Contract::limit('maxShortText'));

        $ratio = $p['aspectRatio'] ?? null;
        if (! is_int($ratio) && ! is_float($ratio)) {
            $this->add("{$path}.aspectRatio", 'Expected a number.');
        } elseif ($ratio <= 0 || $ratio > 10) {
            $this->add("{$path}.aspectRatio", 'Expected a ratio between 0 and 10.');
        }

        $this->optionalAction($p, 'action', $path);
    }

    /** @param array<string, mixed> $p */
    private function buttonRowProps(array $p, string $path): void
    {
        $this->allowOnly($p, $path, Contract::fields('sectionProps.button_row'));
        $buttons = $p['buttons'] ?? null;

        if (! is_array($buttons) || ! array_is_list($buttons) || $buttons === []) {
            $this->add("{$path}.buttons", 'Add at least one button.');

            return;
        }

        if (count($buttons) > Contract::limit('maxButtons')) {
            $this->add("{$path}.buttons", 'Too many buttons in one row.');

            return;
        }

        foreach ($buttons as $index => $button) {
            $buttonPath = "{$path}.buttons.{$index}";
            if (! $this->isMap($button)) {
                $this->add($buttonPath, 'Expected an object.');

                continue;
            }
            $this->allowOnly($button, $buttonPath, Contract::fields('button'));
            $this->requireId($button['id'] ?? null, "{$buttonPath}.id");
            $this->requireText($button['label'] ?? null, "{$buttonPath}.label", 40);
            $this->validateAction($button['action'] ?? null, "{$buttonPath}.action");
        }
    }

    /** @param array<string, mixed> $p */
    private function openingHoursProps(array $p, string $path): void
    {
        $this->allowOnly($p, $path, Contract::fields('sectionProps.opening_hours'));
        $this->optionalText($p, 'title', $path, Contract::limit('maxShortText'));
        $this->optionalText($p, 'note', $path, Contract::limit('maxShortText'));

        $rows = $p['rows'] ?? null;
        if (! is_array($rows) || ! array_is_list($rows)) {
            $this->add("{$path}.rows", 'Expected a list.');

            return;
        }
        if (count($rows) > Contract::limit('maxHoursRows')) {
            $this->add("{$path}.rows", 'Too many rows.');

            return;
        }

        foreach ($rows as $index => $row) {
            $rowPath = "{$path}.rows.{$index}";
            if (! $this->isMap($row)) {
                $this->add($rowPath, 'Expected an object.');

                continue;
            }
            $this->allowOnly($row, $rowPath, Contract::fields('hoursRow'));
            $this->requireText($row['day'] ?? null, "{$rowPath}.day", 40);
            $this->requireText($row['hours'] ?? null, "{$rowPath}.hours", 40);
        }
    }

    /** @param array<string, mixed> $p */
    private function loyaltyCardProps(array $p, string $path): void
    {
        $this->allowOnly($p, $path, Contract::fields('sectionProps.loyalty_card'));
        $this->requireText($p['title'] ?? null, "{$path}.title", Contract::limit('maxShortText'));
        $this->optionalText($p, 'subtitle', $path, Contract::limit('maxShortText'));
        $this->requireText($p['rewardLabel'] ?? null, "{$path}.rewardLabel", Contract::limit('maxShortText'));

        $required = $p['stampsRequired'] ?? null;
        if (! is_int($required) || $required < 1 || $required > 50) {
            $this->add("{$path}.stampsRequired", 'Expected a whole number between 1 and 50.');
        }

        // A placeholder for the preview only — the shell replaces it with the
        // real count, so it must never exceed what it is a placeholder for.
        $earned = $p['stampsEarned'] ?? null;
        if (! is_int($earned) || $earned < 0) {
            $this->add("{$path}.stampsEarned", 'Expected a whole number of 0 or more.');
        } elseif (is_int($required) && $earned > $required) {
            $this->add("{$path}.stampsEarned", 'Cannot be more than the stamps required.');
        }
    }

    /** @param array<string, mixed> $p */
    private function bookingCtaProps(array $p, string $path): void
    {
        $this->allowOnly($p, $path, Contract::fields('sectionProps.booking_cta'));
        $this->requireText($p['title'] ?? null, "{$path}.title", Contract::limit('maxShortText'));
        $this->optionalText($p, 'body', $path, Contract::limit('maxText'));
        $this->requireText($p['ctaLabel'] ?? null, "{$path}.ctaLabel", 40);
        $this->validateAction($p['ctaAction'] ?? null, "{$path}.ctaAction");
        $this->validateChips($p['services'] ?? null, "{$path}.services", required: false);
    }

    /** @param array<string, mixed> $p */
    private function contactBlockProps(array $p, string $path): void
    {
        $this->allowOnly($p, $path, Contract::fields('sectionProps.contact_block'));
        $this->optionalText($p, 'title', $path, Contract::limit('maxShortText'));
        $this->optionalText($p, 'address', $path, Contract::limit('maxText'));
        $this->optionalPhone($p, 'phone', $path);
        $this->optionalUrl($p, 'mapUrl', $path);

        if (array_key_exists('email', $p) && $p['email'] !== null) {
            $email = $p['email'];
            if (! is_string($email) || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->add("{$path}.email", 'Expected an email address.');
            }
        }
    }

    /* --- Shared value types -------------------------------------------- */

    private function validateChips(mixed $chips, string $path, bool $required): void
    {
        if (! is_array($chips) || ! array_is_list($chips)) {
            $this->add($path, 'Expected a list.');

            return;
        }
        if ($required && $chips === []) {
            $this->add($path, 'Add at least one.');

            return;
        }
        if (count($chips) > Contract::limit('maxChips')) {
            $this->add($path, 'Too many.');

            return;
        }

        foreach ($chips as $index => $chip) {
            $chipPath = "{$path}.{$index}";
            if (! $this->isMap($chip)) {
                $this->add($chipPath, 'Expected an object.');

                continue;
            }
            $this->allowOnly($chip, $chipPath, Contract::fields('chip'));
            $this->requireId($chip['id'] ?? null, "{$chipPath}.id");
            $this->requireText($chip['label'] ?? null, "{$chipPath}.label", 40);
            $this->optionalAction($chip, 'action', $chipPath);
        }
    }

    private function validateItemSource(mixed $source, string $path): void
    {
        if (! $this->isMap($source)) {
            $this->add($path, 'Expected an object.');

            return;
        }

        $kind = $source['kind'] ?? null;

        if ($kind === 'literal') {
            $this->allowOnly($source, $path, Contract::fields('itemSourceLiteral'));
            $items = $source['items'] ?? null;

            if (! is_array($items) || ! array_is_list($items)) {
                $this->add("{$path}.items", 'Expected a list.');

                return;
            }
            if (count($items) > Contract::limit('maxItems')) {
                $this->add("{$path}.items", 'Too many items to bake into a config. Use a live source.');

                return;
            }

            foreach ($items as $index => $item) {
                $itemPath = "{$path}.items.{$index}";
                if (! $this->isMap($item)) {
                    $this->add($itemPath, 'Expected an object.');

                    continue;
                }
                $this->allowOnly($item, $itemPath, Contract::fields('item'));
                $this->requireId($item['id'] ?? null, "{$itemPath}.id");
                $this->requireText($item['title'] ?? null, "{$itemPath}.title", Contract::limit('maxShortText'));
                $this->optionalText($item, 'subtitle', $itemPath, Contract::limit('maxShortText'));
                // Pre-formatted including currency: the shell does no money maths.
                $this->optionalText($item, 'price', $itemPath, 32);
                $this->optionalUrl($item, 'imageUrl', $itemPath);
                $this->optionalAction($item, 'action', $itemPath);
            }

            return;
        }

        if ($kind === 'ref') {
            $this->allowOnly($source, $path, Contract::fields('itemSourceRef'));

            // An opaque key. The shell sends it to our proxy, which attaches
            // the real credential server-side — the credential itself never
            // appears in a config, which is why this is a bare token and not,
            // say, a URL with a query string someone could hide a key in.
            $ref = $source['sourceRef'] ?? null;
            if (! is_string($ref) || ! preg_match(Contract::pattern('sourceRef'), $ref)) {
                $this->add("{$path}.sourceRef", 'Expected a source reference key.');
            }

            if (array_key_exists('limit', $source) && $source['limit'] !== null) {
                $limit = $source['limit'];
                if (! is_int($limit) || $limit < 1 || $limit > Contract::limit('maxItems')) {
                    $this->add("{$path}.limit", 'Expected a whole number between 1 and '.Contract::limit('maxItems').'.');
                }
            }

            return;
        }

        $this->add("{$path}.kind", "Expected 'literal' or 'ref'.");
    }

    private function validateAction(mixed $action, string $path): void
    {
        if (! $this->isMap($action)) {
            $this->add($path, 'Expected an action.');

            return;
        }

        $type = $action['type'] ?? null;
        if (! is_string($type) || ! in_array($type, Contract::vocabulary('actionTypes'), true)) {
            $this->add("{$path}.type", 'Unknown action.');

            return;
        }

        match ($type) {
            'navigate' => $this->navigateAction($action, $path),
            'openUrl' => $this->openUrlAction($action, $path),
            'share' => $this->shareAction($action, $path),
            'addToCart' => $this->addToCartAction($action, $path),
            'call' => $this->callAction($action, $path),
            'whatsapp' => $this->whatsappAction($action, $path),
            'submitForm' => $this->submitFormAction($action, $path),
            default => $this->add("{$path}.type", 'Unknown action.'),
        };
    }

    /** @param array<string, mixed> $a */
    private function navigateAction(array $a, string $path): void
    {
        $this->allowOnly($a, $path, Contract::fields('actionFields.navigate'));
        // Existence is checked in validateNavigationTargets, once all screens
        // are known — order in the config must not matter.
        $this->requireId($a['screenId'] ?? null, "{$path}.screenId");
    }

    /** @param array<string, mixed> $a */
    private function openUrlAction(array $a, string $path): void
    {
        $this->allowOnly($a, $path, Contract::fields('actionFields.openUrl'));
        $this->requireUrl($a['url'] ?? null, "{$path}.url");
    }

    /** @param array<string, mixed> $a */
    private function shareAction(array $a, string $path): void
    {
        $this->allowOnly($a, $path, Contract::fields('actionFields.share'));
        $this->requireText($a['text'] ?? null, "{$path}.text", Contract::limit('maxShortText'));
        $this->optionalUrl($a, 'url', $path);
    }

    /** @param array<string, mixed> $a */
    private function addToCartAction(array $a, string $path): void
    {
        $this->allowOnly($a, $path, Contract::fields('actionFields.addToCart'));
        $this->requireId($a['itemId'] ?? null, "{$path}.itemId");

        if (array_key_exists('quantity', $a) && $a['quantity'] !== null) {
            $quantity = $a['quantity'];
            if (! is_int($quantity) || $quantity < 1 || $quantity > 99) {
                $this->add("{$path}.quantity", 'Expected a whole number between 1 and 99.');
            }
        }
    }

    /** @param array<string, mixed> $a */
    private function callAction(array $a, string $path): void
    {
        $this->allowOnly($a, $path, Contract::fields('actionFields.call'));
        $this->requirePhone($a['phone'] ?? null, "{$path}.phone");
    }

    /** @param array<string, mixed> $a */
    private function whatsappAction(array $a, string $path): void
    {
        $this->allowOnly($a, $path, Contract::fields('actionFields.whatsapp'));
        $this->requirePhone($a['phone'] ?? null, "{$path}.phone");
        $this->optionalText($a, 'message', $path, Contract::limit('maxShortText'));
    }

    /** @param array<string, mixed> $a */
    private function submitFormAction(array $a, string $path): void
    {
        $this->allowOnly($a, $path, Contract::fields('actionFields.submitForm'));
        $this->requireId($a['formId'] ?? null, "{$path}.formId");
    }

    /**
     * Walks the whole config for `navigate` actions and checks their targets.
     *
     * Done in a second pass because a section on screen 1 may legitimately
     * navigate to screen 9. A dangling target is a button that does nothing on
     * a real customer's phone — silent, and exactly the kind of thing nobody
     * finds until someone complains.
     *
     * @param  array<string, mixed>  $config
     * @param  list<string>  $screenIds
     */
    private function validateNavigationTargets(array $config, array $screenIds, string $path = ''): void
    {
        $walk = function (mixed $node, string $at) use (&$walk, $screenIds): void {
            if (! is_array($node)) {
                return;
            }

            if ($this->isMap($node)
                && ($node['type'] ?? null) === 'navigate'
                && is_string($node['screenId'] ?? null)
                && ! in_array($node['screenId'], $screenIds, true)
            ) {
                $this->add("{$at}.screenId", 'This points at a screen that does not exist.');
            }

            foreach ($node as $key => $child) {
                $walk($child, $at === '' ? (string) $key : "{$at}.{$key}");
            }
        };

        $walk($config, $path);
    }

    /* --- Primitives ----------------------------------------------------- */

    /**
     * The rule that keeps credentials out of configs: anything not named is
     * refused, rather than stored and forwarded to a CDN.
     *
     * @param  array<string, mixed>  $value
     * @param  list<string>  $allowed
     */
    private function allowOnly(array $value, string $path, array $allowed): void
    {
        foreach (array_keys($value) as $key) {
            if (! in_array((string) $key, $allowed, true)) {
                $at = $path === '' ? (string) $key : "{$path}.{$key}";
                $this->add($at, 'Unexpected field.');
            }
        }
    }

    private function requireText(mixed $value, string $path, int $max): void
    {
        if (! is_string($value) || trim($value) === '') {
            $this->add($path, 'This is required.');

            return;
        }
        if (mb_strlen($value) > $max) {
            $this->add($path, "Keep this under {$max} characters.");
        }
    }

    /** @param array<string, mixed> $bag */
    private function optionalText(array $bag, string $key, string $path, int $max): void
    {
        if (! array_key_exists($key, $bag) || $bag[$key] === null) {
            return;
        }
        $value = $bag[$key];
        if (! is_string($value)) {
            $this->add("{$path}.{$key}", 'Expected text.');

            return;
        }
        if (mb_strlen($value) > $max) {
            $this->add("{$path}.{$key}", "Keep this under {$max} characters.");
        }
    }

    private function requireId(mixed $value, string $path): ?string
    {
        // Ids end up in React keys, navigation lookups and analytics. Keeping
        // them to a boring character set means they never need escaping.
        if (! is_string($value) || ! preg_match(Contract::pattern('id'), $value)) {
            $this->add($path, 'Expected an id of letters, numbers, dashes or underscores.');

            return null;
        }

        return $value;
    }

    private function requireBool(mixed $value, string $path): void
    {
        if (! is_bool($value)) {
            $this->add($path, 'Expected true or false.');
        }
    }

    private function requireColor(mixed $value, string $path): void
    {
        if (! is_string($value) || ! preg_match(Contract::pattern('hexColor'), $value)) {
            $this->add($path, 'Expected a colour like #0f766e.');
        }
    }

    /** @param array<string, mixed> $bag */
    private function optionalColor(array $bag, string $key, string $path): void
    {
        if (! array_key_exists($key, $bag) || $bag[$key] === null) {
            return;
        }
        $this->requireColor($bag[$key], "{$path}.{$key}");
    }

    /**
     * http and https only.
     *
     * `javascript:` here is stored XSS in the panel preview, which renders the
     * same components through react-native-web in a real browser. `data:` and
     * `file:` are the other usual suspects. There is no legitimate config that
     * needs any of them.
     */
    private function requireUrl(mixed $value, string $path): void
    {
        if (! is_string($value) || $value === '') {
            $this->add($path, 'This is required.');

            return;
        }
        if (mb_strlen($value) > Contract::limit('maxUrl')) {
            $this->add($path, 'That address is too long.');

            return;
        }
        if (! preg_match('#^https?://#i', $value) || filter_var($value, FILTER_VALIDATE_URL) === false) {
            $this->add($path, 'Expected a web address starting with http:// or https://.');
        }
    }

    /** @param array<string, mixed> $bag */
    private function optionalUrl(array $bag, string $key, string $path): void
    {
        if (! array_key_exists($key, $bag) || $bag[$key] === null) {
            return;
        }
        $this->requireUrl($bag[$key], "{$path}.{$key}");
    }

    private function requirePhone(mixed $value, string $path): void
    {
        // Deliberately loose on format (international numbers vary wildly) and
        // strict on character set, because this string is handed to a dialler.
        if (! is_string($value) || ! preg_match(Contract::pattern('phone'), $value)) {
            $this->add($path, 'Expected a phone number.');
        }
    }

    /** @param array<string, mixed> $bag */
    private function optionalPhone(array $bag, string $key, string $path): void
    {
        if (! array_key_exists($key, $bag) || $bag[$key] === null) {
            return;
        }
        $this->requirePhone($bag[$key], "{$path}.{$key}");
    }

    /** @param array<string, mixed> $bag */
    private function optionalAction(array $bag, string $key, string $path): void
    {
        if (! array_key_exists($key, $bag) || $bag[$key] === null) {
            return;
        }
        $this->validateAction($bag[$key], "{$path}.{$key}");
    }

    /**
     * `json_decode($json, true)` turns both `{}` and `[]` into the same empty
     * PHP array, and `array_is_list([])` is true. Without the empty case here,
     * a section whose props are all optional (contact_block) could never be
     * saved with an empty props object.
     */
    private function isMap(mixed $value): bool
    {
        return is_array($value) && ($value === [] || ! array_is_list($value));
    }

    private function add(string $path, string $message): void
    {
        $key = $path === '' ? 'config' : "config.{$path}";
        $this->errors[$key][] = $message;
    }

    /**
     * @return array<string, list<string>>
     */
    private function fail(string $path, string $message): array
    {
        $this->add($path, $message);

        return $this->errors;
    }
}
