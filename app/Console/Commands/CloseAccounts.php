<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Support\Tenant\TenantData;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CloseAccounts extends Command
{
    protected $signature = 'appforge:close-accounts {--pretend : list what would go, and erase nothing}';

    protected $description = 'Erase the accounts whose wind-down has run out.';

    public function handle(TenantData $data): int
    {
        $due = Organization::whereNotNull('closing_at')
            ->where('closing_at', '<=', now())
            ->get();

        if ($due->isEmpty()) {
            $this->components->info('No account has run out of time.');

            return self::SUCCESS;
        }

        foreach ($due as $organization) {
            // Read again, one at a time. The list above is a snapshot, and
            // someone clicking "keep my account" while this loop runs would
            // otherwise be erased by a decision taken before they clicked.
            $now = $organization->fresh();

            if ($now === null || $now->closing_at === null || $now->closing_at->isFuture()) {
                $this->components->twoColumnDetail(
                    $organization->name,
                    'taken back while this was running — left alone',
                );

                continue;
            }

            $this->components->twoColumnDetail(
                $organization->name,
                $this->option('pretend') ? 'would be erased' : 'erasing',
            );

            if ($this->option('pretend')) {
                continue;
            }

            // The only record that survives, because the account's own history
            // goes with it: who asked, when, and that we did it.
            Log::warning('[account] erased after its wind-down', [
                'organization' => $organization->getKey(),
                'name' => $organization->name,
                'asked_by' => $organization->closing_by,
                'closing_at' => $organization->closing_at?->toIso8601ZuluString(),
            ]);

            $data->erase($organization);
        }

        return self::SUCCESS;
    }
}
