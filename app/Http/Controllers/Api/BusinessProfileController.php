<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Iso8601;
use App\Models\BusinessProfile;
use App\Support\Profile\BusinessProfileValidator;
use AppForge\Schema\Contract;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class BusinessProfileController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $profiles = $request->user()->organization
            ->businessProfiles()
            ->orderBy('category')
            ->get();

        return response()->json([
            'data' => $profiles->map(fn (BusinessProfile $profile) => $this->payload($profile))->all(),
        ]);
    }

    public function show(Request $request, string $category): JsonResponse
    {
        $this->assertKnownCategory($category);

        $profile = $this->find($request, $category);

        return response()->json([
            'data' => $profile ? $this->payload($profile) : null,
        ]);
    }

    public function update(Request $request, string $category): JsonResponse
    {
        $this->assertKnownCategory($category);

        $data = $this->withoutBlanks($request->input('data'));
        $errors = (new BusinessProfileValidator)->validate($category, $data);

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $organization = $request->user()->organization;

        $profile = $organization->businessProfiles()->updateOrCreate(
            ['category' => $category],
            ['data' => $data],
        );

        return response()->json(['data' => $this->payload($profile)]);
    }

    public function destroy(Request $request, string $category): JsonResponse
    {
        $this->assertKnownCategory($category);

        $profile = $this->find($request, $category);

        $profile?->delete();

        return response()->json(['data' => null]);
    }

    private function withoutBlanks(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_values(array_filter(
                array_map(fn ($item) => $this->withoutBlanks($item), $value),
                fn ($item) => $item !== null && $item !== '' && $item !== [],
            ));
        }

        $cleaned = [];
        foreach ($value as $key => $item) {
            $item = $this->withoutBlanks($item);
            if ($item === null || $item === '' || $item === []) {
                continue;
            }
            $cleaned[$key] = $item;
        }

        return $cleaned;
    }

    private function find(Request $request, string $category): ?BusinessProfile
    {
        return $request->user()->organization
            ->businessProfiles()
            ->where('category', $category)
            ->first();
    }

    private function assertKnownCategory(string $category): void
    {
        if (! in_array($category, Contract::vocabulary('businessCategories'), true)) {
            throw ValidationException::withMessages([
                'category' => ['That business type is not available.'],
            ]);
        }
    }

    private function payload(BusinessProfile $profile): array
    {
        return [
            'id' => $profile->id,
            'category' => $profile->category,
            'industry' => $profile->industry(),
            'data' => $profile->data,
            'updatedAt' => Iso8601::from($profile->updated_at),
        ];
    }
}
