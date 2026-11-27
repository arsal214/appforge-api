<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_users', function (Blueprint $table) {
            // A number the shop can ring about an order, and a photo the
            // customer chose. Both optional, both theirs to change or clear.
            $table->string('phone', 32)->nullable()->after('email');
            $table->string('avatar_path')->nullable()->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('app_users', function (Blueprint $table) {
            $table->dropColumn(['phone', 'avatar_path']);
        });
    }
};
