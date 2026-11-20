<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Text, like `total` beside it: the phone works the sum out and
            // sends the line it showed the customer, so what the shop reads
            // is what was agreed and not a second calculation of it.
            $table->string('tax', 80)->nullable()->after('total');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('tax');
        });
    }
};
