<?php

use App\Support\MediaUrl;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TABLES = [
        'app_configs' => 'config',
        'app_versions' => 'config',
        'business_profiles' => 'data',
    ];

    public function up(): void
    {
        $this->rewrite(static fn (array $data): array => MediaUrl::forStorage($data));
    }

    public function down(): void
    {
        $this->rewrite(static fn (array $data): array => MediaUrl::forDelivery($data));
    }

    private function rewrite(callable $map): void
    {
        foreach (self::TABLES as $table => $column) {
            DB::table($table)
                ->select('id', $column)
                ->orderBy('id')
                ->chunk(200, function ($rows) use ($table, $column, $map) {
                    foreach ($rows as $row) {
                        $decoded = json_decode((string) $row->{$column}, true);

                        if (! is_array($decoded)) {
                            continue;
                        }

                        $mapped = $map($decoded);

                        if ($mapped === $decoded) {
                            continue;
                        }

                        DB::table($table)
                            ->where('id', $row->id)
                            ->update([$column => json_encode($mapped)]);
                    }
                });
        }
    }
};
