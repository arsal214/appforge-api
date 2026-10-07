<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Immutable published snapshots.
 *
 * A version is what a shell on a customer's phone actually downloads. Once it
 * exists it is never edited — if it were, "the app my customer is running" would
 * be a moving target and a rollback would have nothing to roll back to. There
 * is deliberately no `updated_at`: the absence of the column is the cheapest
 * possible enforcement of that rule.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_versions', function (Blueprint $table) {
            $table->char('id', 30)->primary();
            $table->char('app_id', 30);
            $table->unsignedInteger('version');
            $table->unsignedSmallInteger('schema_version');
            $table->json('config');
            $table->string('notes', 500)->nullable();
            $table->char('created_by', 30)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->foreign('app_id')
                ->references('id')->on('apps')
                ->cascadeOnDelete();

            // Two concurrent publishes must not both become version 4. The
            // database decides, not a read-then-write in PHP.
            $table->unique(['app_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_versions');
    }
};
