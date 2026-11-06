<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_events', function (Blueprint $table) {
            $table->char('id', 30)->primary();

            $table->char('organization_id', 30);

            // Nullable and set null on delete: a member can be removed from the
            // team, and what they did before that still has to be readable.
            $table->char('actor_id', 30)->nullable();

            // Which is why the name and email are copied in rather than joined.
            $table->string('actor_name');
            $table->string('actor_email');

            $table->string('action', 32);

            $table->string('subject')->nullable();
            $table->char('subject_id', 30)->nullable();

            $table->json('context')->nullable();

            $table->timestamp('created_at');

            $table->foreign('organization_id')
                ->references('id')
                ->on('organizations')
                ->cascadeOnDelete();

            $table->foreign('actor_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();

            // The one read there is: this organisation's, newest first.
            $table->index(['organization_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_events');
    }
};
