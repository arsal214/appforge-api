<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_user_resets', function (Blueprint $table) {
            $table->id();

            // One live code per person: asking again replaces the last one.
            $table->char('app_user_id', 30)->unique();

            // Keyed with the app key, so a copy of this table alone cannot
            // be walked through a million six-digit guesses offline.
            $table->char('code_hash', 64);
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->foreign('app_user_id')
                ->references('id')->on('app_users')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_user_resets');
    }
};
