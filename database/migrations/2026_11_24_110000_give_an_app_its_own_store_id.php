<?php

use App\Support\BundleId;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('apps', function (Blueprint $table) {
            // Unique across every business, binned apps included: the stores
            // take an id once, whoever asks, and an app in the Bin can still
            // come back wanting its own.
            $table->string('bundle_id', BundleId::MAX_LENGTH)->nullable()->unique()->after('slug');
        });

        // Oldest first, so the app that has had its name longest keeps the
        // plain id and a later namesake gets the numbered one.
        foreach (DB::table('apps')->orderBy('created_at')->orderBy('id')->get(['id', 'name']) as $app) {
            DB::table('apps')->where('id', $app->id)->update([
                'bundle_id' => BundleId::suggest((string) $app->name),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('apps', function (Blueprint $table) {
            $table->dropUnique(['bundle_id']);
            $table->dropColumn('bundle_id');
        });
    }
};
