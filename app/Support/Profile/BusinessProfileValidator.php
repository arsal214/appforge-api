<?php

namespace App\Support\Profile;

use AppForge\Schema\Contract;

class BusinessProfileValidator
{
    private array $errors = [];

    public function validate(string $category, mixed $data): array
    {
        $this->errors = [];

        if (! in_array($category, Contract::vocabulary('businessCategories'), true)) {
            $this->add('category', 'That business type is not available.');

            return $this->errors;
        }

        if (! is_array($data) || ($data !== [] && array_is_list($data))) {
            $this->add('data', 'Expected an object.');

            return $this->errors;
        }

        $encoded = json_encode($data);
        if ($encoded === false || strlen($encoded) > Contract::limit('maxProfileBytes')) {
            $this->add('data', 'That is too much information to save at once.');

            return $this->errors;
        }
        json_decode($encoded, true, Contract::limit('maxDepth'));
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->add('data', 'That is nested too deeply.');

            return $this->errors;
        }

        $allowed = [
            ...Contract::fields('businessProfile.shared'),
            ...Contract::fields("businessProfile.{$category}"),
        ];
        $this->allowOnly($data, 'data', $allowed);

        $this->validateShared($data);

        foreach (['menuGroups', 'serviceGroups', 'productGroups'] as $key) {
            if (array_key_exists($key, $data)) {
                $this->validateGroups($data[$key], "data.{$key}");
            }
        }

        foreach (['deliveryNote', 'shippingNote'] as $key) {
            $this->optionalText($data, $key, 'data', Contract::limit('maxText'));
        }

        foreach (['reservationUrl', 'bookingUrl'] as $key) {
            $this->optionalUrl($data, $key, 'data');
        }

        if (array_key_exists('classTimetable', $data)) {
            $this->validateClassTimetable($data['classTimetable']);
        }

        return $this->errors;
    }

    private function validateShared(array $data): void
    {
        $this->requireText(
            $data['businessName'] ?? null,
            'data.businessName',
            Contract::limit('maxShortText'),
        );

        $this->optionalText($data, 'tagline', 'data', Contract::limit('maxShortText'));
        $this->optionalText($data, 'about', 'data', Contract::limit('maxText'));
        $this->optionalText($data, 'currencySymbol', 'data', 8);

        foreach (['phone', 'whatsapp'] as $key) {
            if (! array_key_exists($key, $data) || $data[$key] === null || $data[$key] === '') {
                continue;
            }
            if (! is_string($data[$key]) || ! preg_match(Contract::pattern('phone'), $data[$key])) {
                $this->add("data.{$key}", 'Enter a valid phone number.');
            }
        }

        if (array_key_exists('email', $data) && $data['email'] !== null && $data['email'] !== '') {
            if (! is_string($data['email']) || ! filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
                $this->add('data.email', 'Enter a valid email address.');
            }
        }

        foreach (['websiteUrl', 'logoUrl'] as $key) {
            $this->optionalUrl($data, $key, 'data');
        }

        if (array_key_exists('accentColor', $data) && $data['accentColor'] !== null) {
            $colour = $data['accentColor'];
            if (! is_string($colour) || ! preg_match(Contract::pattern('hexColor'), $colour)) {
                $this->add('data.accentColor', 'Expected a colour like #10b981.');
            }
        }

        if (array_key_exists('addressLines', $data)) {
            $this->validateStringList(
                $data['addressLines'],
                'data.addressLines',
                Contract::limit('maxAddressLines'),
                Contract::limit('maxShortText'),
            );
        }

        if (array_key_exists('openingHours', $data)) {
            $this->validateOpeningHours($data['openingHours']);
        }

        if (array_key_exists('socialLinks', $data)) {
            $this->validateSocialLinks($data['socialLinks']);
        }
    }

    private function validateGroups(mixed $groups, string $path): void
    {
        if (! is_array($groups) || ! array_is_list($groups)) {
            $this->add($path, 'Expected a list.');

            return;
        }
        if (count($groups) > Contract::limit('maxProfileGroups')) {
            $this->add($path, 'That is more sections than an app can show.');

            return;
        }

        $seenGroupIds = [];
        $entries = 0;

        foreach ($groups as $index => $group) {
            $groupPath = "{$path}.{$index}";

            if (! is_array($group) || array_is_list($group)) {
                $this->add($groupPath, 'Expected an object.');

                continue;
            }

            $this->allowOnly($group, $groupPath, Contract::fields('profileGroup'));

            $id = $this->requireId($group['id'] ?? null, "{$groupPath}.id");
            if ($id !== null) {
                if (in_array($id, $seenGroupIds, true)) {
                    $this->add("{$groupPath}.id", 'Two sections share this id.');
                } else {
                    $seenGroupIds[] = $id;
                }
            }

            $this->requireText(
                $group['name'] ?? null,
                "{$groupPath}.name",
                Contract::limit('maxLabel'),
            );
            $this->optionalText($group, 'note', $groupPath, Contract::limit('maxShortText'));

            if (! array_key_exists('entries', $group)) {
                continue;
            }
            if (! is_array($group['entries']) || ! array_is_list($group['entries'])) {
                $this->add("{$groupPath}.entries", 'Expected a list.');

                continue;
            }

            $seenEntryIds = [];

            foreach ($group['entries'] as $entryIndex => $entry) {
                $entries++;
                if ($entries > Contract::limit('maxProfileEntries')) {
                    $this->add($path, 'That is more items than an app can show.');

                    return;
                }

                $entryPath = "{$groupPath}.entries.{$entryIndex}";

                if (! is_array($entry) || array_is_list($entry)) {
                    $this->add($entryPath, 'Expected an object.');

                    continue;
                }

                $this->allowOnly($entry, $entryPath, Contract::fields('profileEntry'));

                $entryId = $this->requireId($entry['id'] ?? null, "{$entryPath}.id");
                if ($entryId !== null) {
                    if (in_array($entryId, $seenEntryIds, true)) {
                        $this->add("{$entryPath}.id", 'Two items share this id.');
                    } else {
                        $seenEntryIds[] = $entryId;
                    }
                }

                $this->requireText(
                    $entry['title'] ?? null,
                    "{$entryPath}.title",
                    Contract::limit('maxShortText'),
                );
                $this->optionalText($entry, 'description', $entryPath, Contract::limit('maxText'));
                $this->optionalText($entry, 'price', $entryPath, Contract::limit('maxLabel'));
                $this->optionalUrl($entry, 'imageUrl', $entryPath);

                if (array_key_exists('popular', $entry) && ! is_bool($entry['popular'])) {
                    $this->add("{$entryPath}.popular", 'Expected true or false.');
                }
            }
        }
    }

    private function validateOpeningHours(mixed $rows): void
    {
        if (! is_array($rows) || ! array_is_list($rows)) {
            $this->add('data.openingHours', 'Expected a list.');

            return;
        }
        if (count($rows) > Contract::limit('maxProfileHoursRows')) {
            $this->add('data.openingHours', 'That is more rows than a week has days.');

            return;
        }

        foreach ($rows as $index => $row) {
            $path = "data.openingHours.{$index}";

            if (! is_array($row) || array_is_list($row)) {
                $this->add($path, 'Expected an object.');

                continue;
            }

            $this->allowOnly($row, $path, Contract::fields('profileHoursRow'));
            $this->requireText($row['day'] ?? null, "{$path}.day", Contract::limit('maxLabel'));

            $closed = $row['closed'] ?? false;
            if (! is_bool($closed)) {
                $this->add("{$path}.closed", 'Expected true or false.');
            }

            if ($closed === true) {
                $this->optionalText($row, 'hours', $path, Contract::limit('maxLabel'));
            } else {
                $this->requireText(
                    $row['hours'] ?? null,
                    "{$path}.hours",
                    Contract::limit('maxLabel'),
                );
            }
        }
    }

    private function validateSocialLinks(mixed $links): void
    {
        if (! is_array($links) || ! array_is_list($links)) {
            $this->add('data.socialLinks', 'Expected a list.');

            return;
        }
        if (count($links) > Contract::limit('maxProfileSocialLinks')) {
            $this->add('data.socialLinks', 'That is more links than an app can show.');

            return;
        }

        foreach ($links as $index => $link) {
            $path = "data.socialLinks.{$index}";

            if (! is_array($link) || array_is_list($link)) {
                $this->add($path, 'Expected an object.');

                continue;
            }

            $this->allowOnly($link, $path, Contract::fields('profileSocialLink'));

            if (! in_array($link['platform'] ?? null, Contract::vocabulary('socialPlatforms'), true)) {
                $this->add("{$path}.platform", 'That network is not available.');
            }

            if (! array_key_exists('url', $link)) {
                $this->add("{$path}.url", 'This is required.');
            } else {
                $this->optionalUrl($link, 'url', $path);
            }
        }
    }

    private function validateClassTimetable(mixed $slots): void
    {
        if (! is_array($slots) || ! array_is_list($slots)) {
            $this->add('data.classTimetable', 'Expected a list.');

            return;
        }
        if (count($slots) > Contract::limit('maxProfileClassSlots')) {
            $this->add('data.classTimetable', 'That is more classes than an app can show.');

            return;
        }

        foreach ($slots as $index => $slot) {
            $path = "data.classTimetable.{$index}";

            if (! is_array($slot) || array_is_list($slot)) {
                $this->add($path, 'Expected an object.');

                continue;
            }

            $this->allowOnly($slot, $path, Contract::fields('profileClassSlot'));
            $this->requireText($slot['day'] ?? null, "{$path}.day", Contract::limit('maxLabel'));
            $this->requireText($slot['time'] ?? null, "{$path}.time", Contract::limit('maxLabel'));
            $this->requireText($slot['name'] ?? null, "{$path}.name", Contract::limit('maxShortText'));
            $this->optionalText($slot, 'instructor', $path, Contract::limit('maxShortText'));
        }
    }

    private function validateStringList(mixed $value, string $path, int $maxCount, int $maxLength): void
    {
        if (! is_array($value) || ! array_is_list($value)) {
            $this->add($path, 'Expected a list.');

            return;
        }
        if (count($value) > $maxCount) {
            $this->add($path, "Keep this to {$maxCount} lines or fewer.");

            return;
        }
        foreach ($value as $index => $line) {
            if (! is_string($line)) {
                $this->add("{$path}.{$index}", 'Expected text.');
            } elseif (mb_strlen($line) > $maxLength) {
                $this->add("{$path}.{$index}", "Keep this under {$maxLength} characters.");
            }
        }
    }

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

    private function optionalUrl(array $bag, string $key, string $path): void
    {
        if (! array_key_exists($key, $bag) || $bag[$key] === null || $bag[$key] === '') {
            return;
        }
        $value = $bag[$key];
        $at = "{$path}.{$key}";

        if (! is_string($value) || mb_strlen($value) > 2048) {
            $this->add($at, 'Enter a valid web address.');

            return;
        }
        $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));
        if (! in_array($scheme, ['http', 'https'], true)) {
            $this->add($at, 'Enter a web address starting with http:// or https://.');

            return;
        }
        if (filter_var($value, FILTER_VALIDATE_URL) === false) {
            $this->add($at, 'Enter a valid web address.');
        }
    }

    private function requireId(mixed $value, string $path): ?string
    {
        if (! is_string($value) || ! preg_match(Contract::pattern('id'), $value)) {
            $this->add($path, 'Invalid id.');

            return null;
        }

        return $value;
    }

    private function add(string $path, string $message): void
    {
        $this->errors[$path][] = $message;
    }
}
