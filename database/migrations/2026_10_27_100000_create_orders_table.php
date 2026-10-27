<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->char('id', 30)->primary();
            $table->char('app_id', 30);

            // What the customer is told and what the owner says out loud. It is
            // per app and starts at 1, so it cannot be the primary key.
            $table->unsignedInteger('number');

            $table->string('status', 16)->default('new');

            // The cart and the answered detail fields, exactly as the app sent
            // them. Titles and prices are copied rather than referenced: a
            // config edit a week later must not rewrite what someone ordered.
            $table->json('lines');
            $table->json('details');

            $table->string('total', 40)->nullable();
            $table->string('platform', 16)->nullable();

            $table->timestamps();

            $table->foreign('app_id')
                ->references('id')->on('apps')
                ->cascadeOnDelete();

            $table->unique(['app_id', 'number']);
            $table->index(['app_id', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
