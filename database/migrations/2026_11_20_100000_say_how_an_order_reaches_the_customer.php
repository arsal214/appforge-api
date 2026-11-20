<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Null on everything sent before a basket could ask, and on any
            // basket that still does not: "not said" is not "pickup".
            $table->string('handover', 16)->nullable()->after('tax');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('handover');
        });
    }
};
