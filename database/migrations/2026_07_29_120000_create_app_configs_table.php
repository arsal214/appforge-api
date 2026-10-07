<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The working draft: one per app, overwritten on every save.
 *
 * Kept out of the `apps` table so that a list of twenty apps does not drag
 * twenty config blobs across the wire, and so history can be added later
 * without restructuring.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_configs', function (Blueprint $table) {
            $table->char('id', 30)->primary();
            // One draft per app. The unique constraint is the invariant, not a
            // convention someone has to remember in the controller.
            $table->char('app_id', 30)->unique();
            $table->unsignedSmallInteger('schema_version');
            $table->json('config');
            $table->char('updated_by', 30)->nullable();
            $table->timestamps();

            $table->foreign('app_id')
                ->references('id')->on('apps')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_configs');
    }
};
