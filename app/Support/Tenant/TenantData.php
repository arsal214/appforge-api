<?php

namespace App\Support\Tenant;

use App\Http\Resources\AccountEventResource;
use App\Http\Resources\AppResource;
use App\Http\Resources\OrderResource;
use App\Http\Resources\OrganizationInviteResource;
use App\Http\Resources\OrganizationResource;
use App\Http\Resources\PushCampaignResource;
use App\Http\Resources\UploadResource;
use App\Http\Resources\UserResource;
use App\Models\AccountEvent;
use App\Models\App as ClientApp;
use App\Models\BusinessProfile;
use App\Models\Organization;
use App\Models\Upload;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * One place that knows what an organisation owns, because handing a customer
 * their data and erasing it are the same list read twice. Kept apart they
 * drift, and the half that drifts is the one nobody notices: an export missing
 * a table looks complete, and an erase missing one is a breach.
 *
 * `TABLES` is that list, and a test fails if the database grows a
 * tenant-scoped table this class has not been told about.
 */
class TenantData
{
    /**
     * Every table holding something that belongs to one organisation, and what
     * erasing the organisation does to it.
     *
     * `cascade` — the foreign key takes it when the organisation row goes.
     * `erased`  — no foreign key reaches it, so this class deletes it by hand.
     * `kept`    — deliberately survives; the reason is below.
     */
    public const TABLES = [
        'account_events' => 'cascade',
        'apps' => 'cascade',
        'app_configs' => 'cascade',
        'app_credentials' => 'cascade',
        'app_devices' => 'cascade',
        'app_event_daily' => 'cascade',
        'app_versions' => 'cascade',
        'business_profiles' => 'cascade',
        'orders' => 'cascade',
        'organization_invites' => 'cascade',
        'push_campaigns' => 'cascade',
        'uploads' => 'cascade',
        'users' => 'cascade',

        'credential_accesses' => 'erased',
        'password_reset_tokens' => 'erased',
        'personal_access_tokens' => 'erased',
        'sessions' => 'erased',

        // Who on our staff looked at or changed a tenant's account, which is a
        // record about us rather than about them. Erasing it on request would
        // let the request itself destroy the evidence of what was done to the
        // account, so it stays — and it holds no customer's personal data.
        'audit_logs' => 'kept',
    ];

    public function export(Organization $organization): array
    {
        return [
            'exportedAt' => now()->toIso8601ZuluString('millisecond'),
            'organization' => (new OrganizationResource($organization))->resolve(),
            'people' => UserResource::collection($organization->users)->resolve(),
            'invites' => OrganizationInviteResource::collection(
                $organization->invites
            )->resolve(),
            'businesses' => BusinessProfile::where('organization_id', $organization->getKey())
                ->orderBy('created_at')
                ->get()
                ->map(fn (BusinessProfile $profile) => [
                    'id' => $profile->id,
                    'name' => $profile->name,
                    'category' => $profile->category,
                    'data' => $profile->data,
                ])
                ->all(),
            'apps' => ClientApp::withTrashed()
                ->where('organization_id', $organization->getKey())
                ->orderBy('created_at')
                ->get()
                ->map(fn (ClientApp $app) => $this->app($app))
                ->all(),
            'activity' => AccountEventResource::collection(
                AccountEvent::where('organization_id', $organization->getKey())
                    ->orderBy('id')
                    ->get()
            )->resolve(),
            'images' => UploadResource::collection(
                Upload::where('organization_id', $organization->getKey())
                    ->orderBy('created_at')
                    ->get()
            )->resolve(),
        ];
    }

    private function app(ClientApp $app): array
    {
        return (new AppResource($app))->resolve() + [
            'inBin' => $app->trashed(),
            'config' => $app->config?->config,
            'versions' => $app->versions()
                ->orderBy('version')
                ->get(['version', 'created_at'])
                ->map(fn ($row) => [
                    'version' => $row->version,
                    'createdAt' => $row->created_at?->toIso8601ZuluString('millisecond'),
                ])
                ->all(),
            'records' => OrderResource::collection(
                $app->orders()->orderBy('number')->get()
            )->resolve(),
            'notifications' => PushCampaignResource::collection(
                $app->pushCampaigns()->orderBy('created_at')->get()
            )->resolve(),

            // The types that are set up, never a field of any of them. A
            // credential is a secret the customer gave us to use on their
            // behalf, and an export is a file that leaves our control.
            'credentials' => $app->credentials()
                ->orderBy('type')
                ->pluck('type')
                ->all(),

            // Counts, not tokens. A push token identifies one of the shop's
            // own customers and is useless to the shop outside our sending.
            'installs' => [
                'ios' => $app->devices()->where('platform', 'ios')->count(),
                'android' => $app->devices()->where('platform', 'android')->count(),
            ],
            'visits' => DB::table('app_event_daily')
                ->where('app_id', $app->getKey())
                ->orderBy('day')
                ->get(['day', 'event', 'detail', 'platform', 'count'])
                ->map(fn ($row) => (array) $row)
                ->all(),
        ];
    }

    /**
     * Deletes the organisation and everything the list above says goes with it.
     *
     * shortcut: this happens the moment it is asked for, with no grace period
     * to change your mind. Add one when a customer has ever lost an account to
     * a mistake — a `deleting_at` column, a scheduled command and a restore
     * path, rather than a second confirmation nobody reads.
     */
    public function erase(Organization $organization): void
    {
        // Read before anything is deleted: once the organisation row goes, the
        // cascade takes the rows these ids come from.
        $users = $organization->users()->get(['id', 'email']);
        $paths = Upload::where('organization_id', $organization->getKey())->pluck('path');

        DB::transaction(function () use ($organization, $users) {
            DB::table('personal_access_tokens')
                ->where('tokenable_type', (new User)->getMorphClass())
                ->whereIn('tokenable_id', $users->pluck('id'))
                ->delete();

            DB::table('sessions')->whereIn('user_id', $users->pluck('id'))->delete();

            DB::table('password_reset_tokens')
                ->whereIn('email', $users->pluck('email'))
                ->delete();

            DB::table('credential_accesses')
                ->where('organization_id', $organization->getKey())
                ->delete();

            $organization->delete();
        });

        // After the rows, and outside the transaction: a file deleted for a
        // transaction that then rolls back is gone for nothing, while a file
        // left behind by a committed delete is caught by the sweep command.
        foreach ($paths as $path) {
            Storage::disk('public')->delete($path);
        }
    }
}
