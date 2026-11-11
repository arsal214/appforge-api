<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_event_daily', function (Blueprint $table) {
            $table->char('id', 30)->primary();

            $table->date('day');

            $table->string('event', 32);

            $table->unsignedBigInteger('count')->default(0);

            $table->timestamps();

            $table->unique(['day', 'event']);

            $table->index('day');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_event_daily');
    }
};
