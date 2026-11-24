<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('apps', function (Blueprint $table) {
            // The colour behind the icon while the app opens. On the app,
            // beside the icon it sits under, because both are baked into the
            // binary a store ships. Null means "the app's own background".
            $table->char('splash_color', 7)->nullable()->after('icon_path');
        });
    }

    public function down(): void
    {
        Schema::table('apps', function (Blueprint $table) {
            $table->dropColumn('splash_color');
        });
    }
};
