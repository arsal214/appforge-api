<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Iso8601;
use App\Models\App as ClientApp;
use App\Support\Vault\CredentialType;
use App\Support\Vault\CredentialVault;
use App\Support\Vault\KeyManagement;
use App\Support\Vault\VaultException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CredentialController extends Controller
{
    public function __construct(
        private readonly CredentialVault $vault,
        private readonly KeyManagement $keys,
    ) {}

    public function index(Request $request, string $app): JsonResponse
    {
        $model = $this->findOwned($request, $app);
        $stored = $model->credentials()->get()->keyBy('type');

        $types = [];
        foreach (CredentialType::ALL as $type => $meta) {
            $credential = $stored->get($type);

            $types[] = [
                'type' => $type,
                'label' => $meta['label'],
                'fields' => $meta['fields'],
                'use' => $meta['use'],
                'configured' => $credential !== null,
                'hint' => $credential?->hint,
                'lastUsedAt' => $credential
                    ? Iso8601::from($credential->last_used_at)
                    : null,
                'updatedAt' => $credential
                    ? Iso8601::from($credential->updated_at)
                    : null,
            ];
        }

        return response()->json([
            'data' => [
                'credentials' => $types,

                'keyProvider' => $this->keys->describe(),
                'productionGrade' => $this->keys->isProductionGrade(),
            ],
        ]);
    }

    public function store(Request $request, string $app, string $type): JsonResponse
    {
        $model = $this->findOwned($request, $app);

        if (! CredentialType::exists($type)) {
            abort(404);
        }

        $rules = [];
        foreach (CredentialType::fields($type) as $field) {
            $rules[$field] = ['required', 'string', 'min:1', 'max:8000'];
        }
        $rules['label'] = ['nullable', 'string', 'max:120'];

        $validated = $request->validate($rules);
        $label = $validated['label'] ?? null;
        unset($validated['label']);

        try {
            $this->vault->put(
                $model,
                $type,
                $validated,
                $request->user()->getKey(),
                $label,
            );
        } catch (VaultException $exception) {

            report($exception);

            throw ValidationException::withMessages([
                'credential' => ['We could not store that securely. Please try again.'],
            ]);
        }

        return response()->json(['data' => null], 201);
    }

    public function destroy(Request $request, string $app, string $type): JsonResponse
    {
        $model = $this->findOwned($request, $app);
        $credential = $model->credentials()->where('type', $type)->firstOrFail();

        $this->vault->forget($credential, $request->user()->getKey());

        return response()->json(['data' => null]);
    }

    private function findOwned(Request $request, string $id): ClientApp
    {
        return $request->user()->organization->apps()->findOrFail($id);
    }
}
