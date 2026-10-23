<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_credentials', function (Blueprint $table) {
            $table->char('id', 30)->primary();
            $table->char('app_id', 30);

            $table->char('organization_id', 30);

            $table->string('type', 32);

            $table->text('ciphertext');

            $table->text('wrapped_key');

            $table->string('key_provider', 32);

            $table->string('hint', 64)->nullable();
            $table->string('label', 120)->nullable();

            $table->timestamp('last_used_at')->nullable();
            $table->char('created_by', 30)->nullable();
            $table->timestamps();

            $table->foreign('app_id')
                ->references('id')->on('apps')
                ->cascadeOnDelete();

            $table->unique(['app_id', 'type']);
        });

        Schema::create('credential_accesses', function (Blueprint $table) {
            $table->char('id', 30)->primary();

            $table->char('credential_id', 30)->nullable();
            $table->char('app_id', 30)->nullable();
            $table->char('organization_id', 30)->nullable();
            $table->string('type', 32)->nullable();

            $table->string('action', 32);

            $table->string('actor_type', 32);
            $table->char('actor_id', 30)->nullable();

            $table->string('purpose', 120)->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['organization_id', 'created_at']);
            $table->index(['credential_id', 'created_at']);
            $table->index(['action', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credential_accesses');
        Schema::dropIfExists('app_credentials');
    }
};
