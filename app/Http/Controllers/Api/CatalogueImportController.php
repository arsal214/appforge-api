<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Iso8601;
use App\Models\App as ClientApp;
use App\Support\Activity;
use App\Support\Config\ConfigValidator;
use App\Support\Import\CatalogueImporter;
use App\Support\Import\CatalogueWriter;
use App\Support\Import\ImportFailed;
use App\Support\Import\ShopifyImporter;
use App\Support\Import\WooCommerceImporter;
use App\Support\MediaUrl;
use App\Support\Vault\CredentialVault;
use AppForge\Schema\Contract;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CatalogueImportController extends Controller
{
    public function store(Request $request, string $app): JsonResponse
    {
        $model = $this->findOwned($request, $app);

        $validated = $request->validate([
            'type' => ['required', 'string', 'in:woocommerce,shopify'],
        ]);

        $credential = $model->credentials()
            ->where('type', $validated['type'])
            ->first();

        if ($credential === null) {
            throw ValidationException::withMessages([
                'type' => ['Connect that store on the Integrations page first.'],
            ]);
        }

        $limit = Contract::limit('maxItems');

        try {
            $values = app(CredentialVault::class)->reveal(
                $credential,
                purpose: 'catalogue import',
                actorType: 'user',
                actorId: $request->user()->getKey(),
            );

            $fetched = $this->importerFor($validated['type'])->fetch($values, $limit);
        } catch (ImportFailed $failure) {
            return $this->refuse($failure);
        } catch (\Throwable) {
            return $this->refuse(ImportFailed::because(
                'unavailable',
                'We could not read your store just now. Try again in a moment.',
            ));
        }

        // A store is free to ignore per_page, so the cap is enforced here.
        $items = array_slice($fetched, 0, $limit);
        $capped = count($fetched) > $limit;

        if ($items === []) {
            return $this->refuse(ImportFailed::because(
                'nothing_to_import',
                'That store answered, but it has no published products to import.',
            ));
        }

        $current = $model->config;

        if ($current === null) {
            return $this->refuse(ImportFailed::because(
                'no_config',
                'Open the builder once before importing, so there is an app to put the products in.',
            ));
        }

        try {
            $next = CatalogueWriter::write(
                MediaUrl::forDelivery($current->config),
                $items,
            );
        } catch (ImportFailed $failure) {
            return $this->refuse($failure);
        }

        $errors = (new ConfigValidator)->validate($next);

        if ($errors !== []) {
            report(new \RuntimeException(
                'A catalogue import produced an invalid config: '.json_encode($errors),
            ));

            return $this->refuse(ImportFailed::because(
                'would_not_validate',
                'Those products did not fit this app. Nothing was changed, and we have been told.',
            ));
        }

        $record = $model->config()->updateOrCreate(
            ['app_id' => $model->id],
            [
                'schema_version' => Contract::SCHEMA_VERSION,
                'config' => MediaUrl::forStorage($next),
                'updated_by' => $request->user()->getKey(),
            ],
        );

        // The same rule the builder's own save follows: changing a rejected
        // app is the customer fixing it, so it goes back to a draft. Without
        // this, importing products to fix a rejection left the app rejected
        // until they also touched the builder.
        if ($model->status === 'rejected') {
            $model->update(['status' => 'draft']);
        }

        Activity::record(
            $request->user(),
            'catalogue.imported',
            $model->name,
            $model->getKey(),
            ['from' => $validated['type'], 'items' => count($items), 'capped' => $capped],
        );

        return response()->json([
            'data' => [
                'imported' => count($items),
                'capped' => $capped,
                'limit' => $limit,
                'config' => MediaUrl::forDelivery($record->config),
                'updatedAt' => Iso8601::from($record->updated_at),
            ],
        ]);
    }

    private function importerFor(string $type): CatalogueImporter
    {
        return match ($type) {
            'woocommerce' => new WooCommerceImporter,
            'shopify' => new ShopifyImporter,
        };
    }

    private function refuse(ImportFailed $failure): JsonResponse
    {
        return response()->json([
            'message' => $failure->getMessage(),
            'reason' => $failure->reason,
        ], 422);
    }

    private function findOwned(Request $request, string $id): ClientApp
    {
        return $request->user()->organization->apps()->findOrFail($id);
    }
}
