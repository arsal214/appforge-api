<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Push notifications: who can be reached, and what was sent.
 *
 * Two tables with very different characters. `app_devices` is written by phones
 * we do not control, through an unauthenticated endpoint, and grows to the size
 * of a customer's install base. `push_campaigns` is written by the panel, is
 * small, and is a permanent record of what a business said to its customers —
 * which matters the first time one of them complains.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_devices', function (Blueprint $table) {
            $table->char('id', 30)->primary();
            $table->char('app_id', 30);

            // The APNs/FCM token. Pseudonymous rather than anonymous: it
            // identifies a device until the OS rotates it, so it is personal
            // data and is deleted with the app.
            $table->string('token', 512);
            // Hashed copy, because a 512-char token cannot carry a unique index
            // in utf8mb4 (the 3072-byte key limit). This is what makes
            // re-registering the same device an update instead of a duplicate.
            $table->char('token_hash', 64);

            $table->string('platform', 16);
            $table->string('locale', 16)->nullable();
            $table->string('shell_version', 32)->nullable();
            // Stale tokens are the bulk of a mature install base. This is what a
            // cleanup job prunes on, and what an audience count excludes.
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
            // What tapping the notification opens. Stored as an SDUI action, so
            // it is the same closed vocabulary the shell already knows how to
            // execute — a push payload cannot smuggle in behaviour the app does
            // not already have.
            $table->json('action')->nullable();

            $table->string('status', 16)->default('draft');
            $table->timestamp('scheduled_for')->nullable();
            $table->timestamp('sent_at')->nullable();

            // Recorded at send time, not derived later. "How many did this
            // reach?" must stay answerable after the install base has changed.
            $table->unsignedInteger('recipient_count')->nullable();
            $table->unsignedInteger('failure_count')->nullable();
            $table->string('failure_reason', 500)->nullable();

            $table->char('created_by', 30)->nullable();
            $table->timestamps();

            $table->foreign('app_id')
                ->references('id')->on('apps')
                ->cascadeOnDelete();

            // The query the scheduler runs every minute.
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
