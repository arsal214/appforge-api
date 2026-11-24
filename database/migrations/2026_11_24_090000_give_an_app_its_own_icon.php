<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('apps', function (Blueprint $table) {
            // Held as a path under /storage/, like every other image we host,
            // so it still resolves on a machine whose APP_URL is different.
            // On the app and not in its config: the icon is part of the
            // binary a store ships, not of the screens fetched at runtime.
            $table->string('icon_path')->nullable()->after('accent_color');
        });
    }

    public function down(): void
    {
        Schema::table('apps', function (Blueprint $table) {
            $table->dropColumn('icon_path');
        });
    }
};
