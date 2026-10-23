<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_devices', function (Blueprint $table) {
            $table->char('id', 30)->primary();
            $table->char('app_id', 30);

            $table->string('token', 512);

            $table->char('token_hash', 64);

            $table->string('platform', 16);
            $table->string('locale', 16)->nullable();
            $table->string('shell_version', 32)->nullable();

            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->foreign('app_id')
                ->references('id')->on('apps')
                ->cascadeOnDelete();

            $table->unique(['app_id', 'token_hash']);
            $table->index(['app_id', 'last_seen_at']);
        });

        Schema::create('push_campaigns', function (Blueprint $table) {
            $table->char('id', 30)->primary();
            $table->char('app_id', 30);

            $table->string('title', 120);
            $table->string('body', 400);

            $table->json('action')->nullable();

            $table->string('status', 16)->default('draft');
            $table->timestamp('scheduled_for')->nullable();
            $table->timestamp('sent_at')->nullable();

            $table->unsignedInteger('recipient_count')->nullable();
            $table->unsignedInteger('failure_count')->nullable();
            $table->string('failure_reason', 500)->nullable();

            $table->char('created_by', 30)->nullable();
            $table->timestamps();

            $table->foreign('app_id')
                ->references('id')->on('apps')
                ->cascadeOnDelete();

            $table->index(['status', 'scheduled_for']);
            $table->index(['app_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_campaigns');
        Schema::dropIfExists('app_devices');
    }
};
