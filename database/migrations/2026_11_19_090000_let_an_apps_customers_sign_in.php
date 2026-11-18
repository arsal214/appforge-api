<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_users', function (Blueprint $table) {
            $table->char('id', 30)->primary();
            $table->char('app_id', 30);

            $table->string('name', 120);
            $table->string('email', 190);
            $table->string('password');

            $table->timestamps();

            $table->foreign('app_id')
                ->references('id')->on('apps')
                ->cascadeOnDelete();

            // Per app, not global: the same person is a different customer of
            // two shops, and neither shop may learn they use the other.
            $table->unique(['app_id', 'email']);
        });

        Schema::create('app_user_tokens', function (Blueprint $table) {
            $table->id();
            $table->char('app_user_id', 30);

            $table->char('token_hash', 64)->unique();

            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->foreign('app_user_id')
                ->references('id')->on('app_users')
                ->cascadeOnDelete();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->char('app_user_id', 30)->nullable()->after('app_id');

            // The order outlives the account: the shop still made the food.
            $table->foreign('app_user_id')
                ->references('id')->on('app_users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['app_user_id']);
            $table->dropColumn('app_user_id');
        });

        Schema::dropIfExists('app_user_tokens');
        Schema::dropIfExists('app_users');
    }
};
