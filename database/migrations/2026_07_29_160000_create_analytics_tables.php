<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_event_daily', function (Blueprint $table) {
            $table->char('app_id', 30);

            $table->date('day');

            $table->string('event', 24);

            $table->string('detail', 64)->default('');

            $table->string('platform', 16);

            $table->unsignedBigInteger('count')->default(0);
            $table->timestamps();

            $table->foreign('app_id')
                ->references('id')->on('apps')
                ->cascadeOnDelete();

            $table->primary(['app_id', 'day', 'event', 'detail', 'platform']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_event_daily');
    }
};
