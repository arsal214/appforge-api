<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_configs', function (Blueprint $table) {
            $table->char('id', 30)->primary();

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
