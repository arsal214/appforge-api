<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('form_id', 64)->nullable()->after('kind');
        });

        DB::table('orders')->where('kind', 'request')->update(['kind' => 'booking']);
    }

    public function down(): void
    {
        DB::table('orders')->whereIn('kind', ['booking', 'message'])->update(['kind' => 'request']);

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('form_id');
        });
    }
};
