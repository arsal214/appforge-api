<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->timestamp('closing_at')->nullable()->after('slug');

            // Who asked, kept so the banner can say it and so a dispute about
            // "I never asked for this" has an answer. Null on delete, because
            // the person who asked may have been removed from the team since.
            $table->char('closing_by', 30)->nullable()->after('closing_at');

            $table->foreign('closing_by')
                ->references('id')
                ->on('users')
                ->nullOnDelete();

            // The sweep's only read: everything already past its date.
            $table->index('closing_at');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropForeign(['closing_by']);
            $table->dropIndex(['closing_at']);
            $table->dropColumn(['closing_at', 'closing_by']);
        });
    }
};
