<?php

namespace App\Console\Commands;

use App\Support\MediaReferences;
use App\Support\MediaUrl;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class SweepUploads extends Command
{
    protected $signature = 'appforge:sweep-uploads
        {--delete : actually remove what is found, instead of only listing it}
        {--days=7 : leave anything uploaded more recently than this alone}';

    protected $description = 'Find uploaded images nothing uses any more.';

    public function handle(): int
    {
        $days = max(0, (int) $this->option('days'));

        $cutoff = now()->subDays($days);

        $candidates = DB::table('uploads')->where('created_at', '<', $cutoff)->get();
        $orphans = $candidates->filter(
            fn (object $row): bool => MediaReferences::using(
                MediaUrl::reference($row->path),
                $row->organization_id,
            ) === []
        );

        $unaccounted = $this->filesWithNoRow();

        if ($orphans->isEmpty() && $unaccounted === []) {
            $this->components->info(
                "Nothing to sweep. {$candidates->count()} upload(s) older than {$days} day(s), all in use."
            );

            return self::SUCCESS;
        }

        $this->report($orphans, $unaccounted);

        if (! $this->option('delete')) {
            $this->line('');
            $this->components->warn('Nothing was deleted. Run again with --delete.');

            return self::SUCCESS;
        }

        foreach ($orphans as $row) {
            DB::table('uploads')->where('id', $row->id)->delete();
            Storage::disk('public')->delete($row->path);
        }

        $this->line('');
        $this->components->info('Deleted '.$orphans->count().' upload(s).');

        return self::SUCCESS;
    }

    private function report(Collection $orphans, array $unaccounted): void
    {
        $bytes = 0;

        foreach ($orphans as $row) {
            $bytes += (int) $row->bytes;
            $this->line("  {$row->name}  ({$this->size((int) $row->bytes)})  {$row->path}");
        }

        if ($orphans->isNotEmpty()) {
            $this->line('');
            $this->components->info(
                $orphans->count().' unused upload(s), '.$this->size($bytes).'.'
            );
        }

        if ($unaccounted === []) {
            return;
        }

        $this->line('');
        $this->components->warn(
            count($unaccounted).' file(s) on disk with no record. Left alone — '
            .'a file in an unexpected place has no owner to attribute it to:'
        );

        foreach ($unaccounted as $path) {
            $this->line("  {$path}");
        }
    }

    private function filesWithNoRow(): array
    {
        $known = DB::table('uploads')->pluck('path')->all();

        return array_values(array_diff(
            Storage::disk('public')->allFiles('profile-images'),
            $known,
        ));
    }

    private function size(int $bytes): string
    {
        return $bytes >= 1048576
            ? round($bytes / 1048576, 1).' MB'
            : max(1, (int) round($bytes / 1024)).' KB';
    }
}
