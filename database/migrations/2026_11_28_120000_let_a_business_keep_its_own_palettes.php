<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            // Colour palettes the team made for themselves, offered in the
            // builder of every one of their apps beside the ready-made ones.
            $table->json('palettes')->nullable()->after('slug');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('palettes');
        });
    }
};
