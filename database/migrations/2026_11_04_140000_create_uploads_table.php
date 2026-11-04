<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const EXTENSION_MIMES = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
    ];

    public function up(): void
    {
        Schema::create('uploads', function (Blueprint $table) {
            $table->char('id', 30)->primary();
            $table->char('organization_id', 30);

            $table->string('path')->unique();

            $table->string('name');
            $table->string('mime', 32);
            $table->unsignedInteger('bytes');
            $table->timestamps();

            $table->foreign('organization_id')
                ->references('id')->on('organizations')
                ->cascadeOnDelete();

            $table->index(['organization_id', 'created_at']);
        });

        $this->adoptWhatIsAlreadyOnDisk();
    }

    public function down(): void
    {
        Schema::dropIfExists('uploads');
    }

    private function adoptWhatIsAlreadyOnDisk(): void
    {
        $disk = Storage::disk('public');
        $organizations = DB::table('organizations')->pluck('id')->all();
        $rows = [];

        foreach ($disk->allFiles('profile-images') as $path) {
            $organizationId = explode('/', $path)[1] ?? null;

            if ($organizationId === null || ! in_array($organizationId, $organizations, true)) {
                continue;
            }

            $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

            $rows[] = [
                'id' => 'upl_'.strtolower((string) Str::ulid()),
                'organization_id' => $organizationId,
                'path' => $path,
                'name' => basename($path),
                'mime' => self::EXTENSION_MIMES[$extension] ?? 'application/octet-stream',
                'bytes' => $disk->size($path),
                'created_at' => date('Y-m-d H:i:s', $disk->lastModified($path)),
                'updated_at' => date('Y-m-d H:i:s', $disk->lastModified($path)),
            ];
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('uploads')->insert($chunk);
        }
    }
};
