<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AccountEventResource;
use App\Http\Resources\OrganizationResource;
use App\Models\AccountEvent;
use App\Models\Organization;
use App\Support\Activity;
use App\Support\Tenant\TenantData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TenantDataController extends Controller
{
    private const PER_PAGE = 50;

    public function export(Request $request, TenantData $data): JsonResponse
    {
        $export = $data->export($request->user()->organization);

        // After the export is built, so a failed one is not recorded as a
        // download that happened.
        Activity::record($request->user(), 'account.exported');

        return response()->json(['data' => $export]);
    }

    public function activity(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'before' => ['nullable', 'string', 'max:30'],
            'kind' => ['nullable', Rule::in(array_keys(AccountEvent::GROUPS))],
        ]);

        $query = AccountEvent::where(
            'organization_id',
            $request->user()->organization_id,
        )->orderByDesc('id');

        if (($validated['kind'] ?? null) !== null) {
            $query->whereIn('action', AccountEvent::GROUPS[$validated['kind']]);
        }

        if (($validated['before'] ?? null) !== null) {
            $query->where('id', '<', $validated['before']);
        }

        $rows = $query->limit(self::PER_PAGE + 1)->get();

        return response()->json([
            'data' => [
                'events' => AccountEventResource::collection(
                    $rows->take(self::PER_PAGE)
                ),
                'hasMore' => $rows->count() > self::PER_PAGE,
            ],
        ]);
    }

    /**
     * Starts the wind-down. Nothing is deleted here — `appforge:close-accounts`
     * does that once the date passes, and until then the account can be taken
     * back with `reopen` below.
     */
    public function destroy(Request $request): JsonResponse
    {
        $organization = $request->user()->organization;

        // Typing the name is the confirmation. A customer who has the name in
        // front of them is reading the dialog; one clicking through a second
        // "are you sure" is not.
        $request->validate(
            ['name' => ['required', 'string', Rule::in([$organization->name])]],
            ['name.in' => 'That is not the name of this business, so nothing was closed.'],
        );

        // Conditional on the column still being null, so that two clicks
        // arriving together cannot both "start" the wind-down: the database
        // decides which one wins, and only that one writes the history line.
        $started = Organization::whereKey($organization->getKey())
            ->whereNull('closing_at')
            ->update([
                'closing_at' => now()->addDays(Organization::GRACE_DAYS),
                'closing_by' => $request->user()->getKey(),
            ]);

        $organization = $organization->fresh();

        if ($started === 1) {
            Activity::record(
                $request->user(),
                'account.closing',
                $organization->name,
                $organization->getKey(),
                ['closingAt' => $organization->closing_at->toIso8601ZuluString('millisecond')],
            );
        }

        return response()->json([
            'data' => new OrganizationResource($organization),
        ]);
    }

    public function reopen(Request $request): JsonResponse
    {
        $organization = $request->user()->organization;

        $takenBack = Organization::whereKey($organization->getKey())
            ->whereNotNull('closing_at')
            ->update(['closing_at' => null, 'closing_by' => null]);

        if ($takenBack === 1) {
            Activity::record(
                $request->user(),
                'account.reopened',
                $organization->name,
                $organization->getKey(),
            );
        }

        return response()->json([
            'data' => new OrganizationResource($organization->fresh()),
        ]);
    }
}
