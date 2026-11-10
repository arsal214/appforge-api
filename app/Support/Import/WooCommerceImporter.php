<?php

namespace App\Support\Import;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

final class WooCommerceImporter implements CatalogueImporter
{
    private const TIMEOUT_SECONDS = 6;

    public function type(): string
    {
        return 'woocommerce';
    }

    public function fetch(array $credential, int $limit): array
    {
        $base = rtrim(SafeUrl::check((string) ($credential['storeUrl'] ?? '')), '/');

        $key = trim((string) ($credential['consumerKey'] ?? ''));
        $secret = trim((string) ($credential['consumerSecret'] ?? ''));

        if ($key === '' || $secret === '') {
            throw ImportFailed::because('no_key', 'These credentials are missing their consumer key.');
        }

        $products = $this->get($base.'/wp-json/wc/v3/products', $key, $secret, [
            'per_page' => $limit,
            'status' => 'publish',
            'orderby' => 'date',
            'order' => 'desc',
        ]);

        if (! array_is_list($products)) {
            throw ImportFailed::because(
                'unexpected_answer',
                'That address answered, but not with a WooCommerce product list. Check the store address.',
            );
        }

        $currency = $this->currency($base, $key, $secret);

        $items = [];

        foreach ($products as $product) {
            if (! is_array($product)) {
                continue;
            }

            $item = $this->itemFrom($product, $currency);

            if ($item !== null) {
                $items[] = $item;
            }
        }

        return $items;
    }

    private function currency(string $base, string $key, string $secret): ?string
    {
        try {
            $answer = $this->get($base.'/wp-json/wc/v3/data/currencies/current', $key, $secret);
        } catch (ImportFailed) {
            return null;
        }

        $code = $answer['code'] ?? null;

        return is_string($code) && $code !== '' ? $code : null;
    }

    private function itemFrom(array $product, ?string $currency): ?array
    {
        $title = trim((string) ($product['name'] ?? ''));
        $sourceId = $product['id'] ?? null;

        if ($title === '' || ! is_scalar($sourceId)) {
            return null;
        }

        $item = [
            'id' => ItemId::for('woo', (string) $sourceId),
            'title' => Text::shorten($title),
        ];

        $subtitle = Text::fromHtml((string) ($product['short_description'] ?? ''))
            ?: Text::fromHtml((string) ($product['description'] ?? ''));

        if ($subtitle !== null) {
            $item['subtitle'] = $subtitle;
        }

        $price = Money::format(
            is_scalar($product['price'] ?? null) ? (string) $product['price'] : null,
            $currency,
        );

        if ($price !== null) {
            $item['price'] = $price;
        }

        $image = $product['images'][0]['src'] ?? null;

        if (is_string($image) && str_starts_with($image, 'https://')) {
            $item['imageUrl'] = $image;
        }

        return $item;
    }

    private function get(string $url, string $key, string $secret, array $query = []): array
    {
        try {
            $response = Http::withBasicAuth($key, $secret)
                ->acceptJson()
                ->withoutRedirecting()
                ->timeout(self::TIMEOUT_SECONDS)
                ->get($url, $query);
        } catch (ConnectionException) {
            throw ImportFailed::because(
                'unreachable',
                'Your store did not answer in time. Try again in a moment.',
            );
        }

        if ($response->status() === 401 || $response->status() === 403) {
            throw ImportFailed::because(
                'rejected',
                'Your store refused the key. Check the consumer key and secret, and that the key has read access.',
            );
        }

        if ($response->redirect()) {
            throw ImportFailed::because(
                'redirected',
                'Your store redirected the request. Save the address it redirects to instead.',
            );
        }

        if ($response->failed()) {
            throw ImportFailed::because(
                'store_error',
                "Your store answered with an error ({$response->status()}).",
            );
        }

        $body = $response->json();

        if (! is_array($body)) {
            throw ImportFailed::because(
                'unexpected_answer',
                'That address answered, but not with the product data WooCommerce sends.',
            );
        }

        return $body;
    }
}
