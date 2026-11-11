<?php

namespace App\Console\Commands;

use App\Models\App;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class EmptyBin extends Command
{
    protected $signature = 'appforge:empty-bin {--pretend : list what would go, and delete nothing}';

    protected $description = 'Delete for good the apps that have sat in the bin past their keep.';

    public function handle(): int
    {
        $cutoff = now()->subDays(App::BIN_DAYS);

        $due = App::onlyTrashed()->where('deleted_at', '<=', $cutoff)->get();

        if ($due->isEmpty()) {
            $this->components->info('No app has sat in the bin long enough.');

            return self::SUCCESS;
        }

        foreach ($due as $app) {
            // Read again: the list above is a snapshot and Restore is one
            // click, so someone taking an app back mid-sweep must win.
            $now = App::withTrashed()->find($app->getKey());

            if ($now === null) {
                continue;
            }

            if (! $now->trashed() || $now->deleted_at->isAfter($cutoff)) {
                $this->components->twoColumnDetail(
                    $app->name,
                    'taken out of the bin while this was running — left alone',
                );

                continue;
            }

            $this->components->twoColumnDetail(
                $app->name,
                $this->option('pretend') ? 'would be deleted' : 'deleting',
            );

            if ($this->option('pretend')) {
                continue;
            }

            Log::warning('[app] deleted after its time in the bin', [
                'app' => $now->getKey(),
                'name' => $now->name,
                'organization' => $now->organization_id,
                'binned_at' => $now->deleted_at?->toIso8601ZuluString(),
            ]);

            $now->forceDelete();
        }

        return self::SUCCESS;
    }
}
