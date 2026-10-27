<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Every row that exists today registered its own organisation, so
            // the default backfills them correctly and must stay 'owner'.
            $table->string('role', 16)->default('owner')->after('email');
        });

        Schema::create('organization_invites', function (Blueprint $table) {
            $table->char('id', 30)->primary();
            $table->char('organization_id', 30);

            $table->string('email');
            $table->string('role', 16);

            // Only the hash is stored. The link is shown to the inviter once and
            // is not recoverable afterwards, for the same reason a password reset
            // token is not: our database is not a place to keep a working key.
            $table->char('token_hash', 64)->unique();

            $table->char('invited_by', 30)->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();

            $table->foreign('organization_id')
                ->references('id')->on('organizations')
                ->cascadeOnDelete();

            $table->foreign('invited_by')
                ->references('id')->on('users')
                ->nullOnDelete();

            $table->unique(['organization_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_invites');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }
};
