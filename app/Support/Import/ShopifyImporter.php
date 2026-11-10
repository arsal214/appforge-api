<?php

namespace App\Support\Import;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

final class ShopifyImporter implements CatalogueImporter
{
    private const API_VERSION = '2026-01';

    private const TIMEOUT_SECONDS = 6;

    public function type(): string
    {
        return 'shopify';
    }

    public function fetch(array $credential, int $limit): array
    {
        $domain = $this->domain((string) ($credential['shopDomain'] ?? ''));
        $token = trim((string) ($credential['accessToken'] ?? ''));

        if ($token === '') {
            throw ImportFailed::because('no_key', 'These credentials are missing their access token.');
        }

        $base = SafeUrl::check("https://{$domain}/admin/api/".self::API_VERSION);

        $answer = $this->get($base.'/products.json', $token, [
            'limit' => $limit,
            'status' => 'active',
        ]);

        $products = $answer['products'] ?? null;

        if (! is_array($products) || ! array_is_list($products)) {
            throw ImportFailed::because(
                'unexpected_answer',
                'That shop answered, but not with a Shopify product list. Check the shop domain.',
            );
        }

        $items = [];

        foreach ($products as $product) {
            if (! is_array($product)) {
                continue;
            }

            $item = $this->itemFrom($product);

            if ($item !== null) {
                $items[] = $item;
            }
        }

        return $items;
    }

    private function domain(string $input): string
    {
        $value = trim($input);

        if ($value === '') {
            throw ImportFailed::because('no_store_url', 'No shop domain was saved with these credentials.');
        }

        if (str_contains($value, '://')) {
            $host = parse_url($value, PHP_URL_HOST);
            $value = is_string($host) ? $host : '';
        }

        $value = trim(explode('/', $value)[0] ?? '');

        if ($value === '') {
            throw ImportFailed::because('bad_store_url', 'That shop domain could not be read.');
        }

        return $value;
    }

    private function itemFrom(array $product): ?array
    {
        $title = trim((string) ($product['title'] ?? ''));
        $sourceId = $product['id'] ?? null;

        if ($title === '' || ! is_scalar($sourceId)) {
            return null;
        }

        $item = [
            'id' => ItemId::for('shopify', (string) $sourceId),
            'title' => Text::shorten($title),
        ];

        $subtitle = Text::fromHtml((string) ($product['body_html'] ?? ''));

        if ($subtitle !== null) {
            $item['subtitle'] = $subtitle;
        }

        $variant = $product['variants'][0] ?? null;

        $price = Money::format(
            is_array($variant) && is_scalar($variant['price'] ?? null)
                ? (string) $variant['price']
                : null,
            is_scalar($variant['presentment_prices'][0]['price']['currency_code'] ?? null)
                ? (string) $variant['presentment_prices'][0]['price']['currency_code']
                : null,
        );

        if ($price !== null) {
            $item['price'] = $price;
        }

        $image = $product['image']['src'] ?? $product['images'][0]['src'] ?? null;

        if (is_string($image) && str_starts_with($image, 'https://')) {
            $item['imageUrl'] = $image;
        }

        return $item;
    }

    private function get(string $url, string $token, array $query = []): array
    {
        try {
            $response = Http::withHeader('X-Shopify-Access-Token', $token)
                ->acceptJson()
                ->withoutRedirecting()
                ->timeout(self::TIMEOUT_SECONDS)
                ->get($url, $query);
        } catch (ConnectionException) {
            throw ImportFailed::because(
                'unreachable',
                'Your shop did not answer in time. Try again in a moment.',
            );
        }

        if ($response->status() === 401 || $response->status() === 403) {
            throw ImportFailed::because(
                'rejected',
                'Your shop refused the token. Check it, and that the app has read_products access.',
            );
        }

        if ($response->redirect()) {
            throw ImportFailed::because(
                'redirected',
                'Your shop redirected the request. Check the shop domain is the one Shopify gave you.',
            );
        }

        if ($response->failed()) {
            throw ImportFailed::because(
                'store_error',
                "Your shop answered with an error ({$response->status()}).",
            );
        }

        $body = $response->json();

        if (! is_array($body)) {
            throw ImportFailed::because(
                'unexpected_answer',
                'That shop answered, but not with the product data Shopify sends.',
            );
        }

        return $body;
    }
}
