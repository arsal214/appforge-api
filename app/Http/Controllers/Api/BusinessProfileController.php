<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Iso8601;
use App\Models\BusinessProfile;
use App\Support\Activity;
use App\Support\MediaUrl;
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
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $profiles->map(fn (BusinessProfile $profile) => $this->payload($profile))->all(),
        ]);
    }

    public function show(BusinessProfile $profile): JsonResponse
    {
        return response()->json(['data' => $this->payload($profile)]);
    }

    public function store(Request $request): JsonResponse
    {
        $category = (string) $request->input('category');
        $this->assertKnownCategory($category);

        $organization = $request->user()->organization;
        $limit = Contract::limit('maxBusinessProfiles');

        if ($organization->businessProfiles()->count() >= $limit) {
            throw ValidationException::withMessages([
                'name' => ["You can save up to {$limit} businesses. Delete one you no longer need first."],
            ]);
        }

        $name = $this->validName($request, $category, $organization->id, null);
        $data = $this->cleanData($category, $request->input('data'));

        $profile = $organization->businessProfiles()->create([
            'category' => $category,
            'name' => $name,
            'data' => MediaUrl::forStorage($data),
        ]);

        Activity::record(
            $request->user(),
            'business.saved',
            $profile->name,
            $profile->getKey(),
            ['category' => $profile->category],
        );

        return response()->json(['data' => $this->payload($profile)], 201);
    }

    public function update(Request $request, BusinessProfile $profile): JsonResponse
    {
        $name = $request->has('name')
            ? $this->validName($request, $profile->category, $profile->organization_id, $profile->id)
            : $profile->name;

        $data = $this->cleanData($profile->category, $request->input('data'));

        $profile->update([
            'name' => $name,
            'data' => MediaUrl::forStorage($data),
        ]);

        Activity::record(
            $request->user(),
            'business.changed',
            $profile->name,
            $profile->getKey(),
        );

        return response()->json(['data' => $this->payload($profile)]);
    }

    public function destroy(Request $request, BusinessProfile $profile): JsonResponse
    {
        $name = $profile->name;
        $id = $profile->getKey();

        $profile->delete();

        Activity::record($request->user(), 'business.removed', $name, $id);

        return response()->json(['data' => null]);
    }

    private function cleanData(string $category, mixed $input): array
    {
        $data = $this->withoutBlanks($input);
        $data = is_array($data) ? $data : [];

        $errors = (new BusinessProfileValidator)->validate($category, $data);

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $data;
    }

    private function validName(
        Request $request,
        string $category,
        string $organizationId,
        ?string $ignoreId,
    ): string {
        $name = trim((string) $request->input('name'));
        $max = Contract::limit('maxLabel');

        if ($name === '') {
            throw ValidationException::withMessages([
                'name' => ['Give this business a name, so you can tell it from the others.'],
            ]);
        }

        if (mb_strlen($name) > $max) {
            throw ValidationException::withMessages([
                'name' => ["Keep the name under {$max} characters."],
            ]);
        }

        $taken = BusinessProfile::query()
            ->where('organization_id', $organizationId)
            ->where('category', $category)
            ->where('name', $name)
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages([
                'name' => ['You already have one saved under that name.'],
            ]);
        }

        return $name;
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
            'name' => $profile->name,
            'data' => MediaUrl::forDelivery($profile->data),
            'updatedAt' => Iso8601::from($profile->updated_at),
        ];
    }
}
