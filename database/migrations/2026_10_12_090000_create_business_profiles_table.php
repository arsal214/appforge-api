<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_profiles', function (Blueprint $table) {
            $table->char('id', 30)->primary();
            $table->char('organization_id', 30);
            $table->string('category', 32);
            $table->json('data');
            $table->timestamps();

            $table->foreign('organization_id')
                ->references('id')->on('organizations')
                ->cascadeOnDelete();

            $table->unique(['organization_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_profiles');
    }
};
