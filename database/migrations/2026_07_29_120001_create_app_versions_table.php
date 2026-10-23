<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

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

            $table->unique(['app_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_versions');
    }
};
