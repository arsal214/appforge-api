<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('apps', function (Blueprint $table) {
            $table->char('business_profile_id', 30)->nullable()->after('category');

            $table->foreign('business_profile_id')
                ->references('id')->on('business_profiles')
                ->nullOnDelete();
        });

        $this->linkWhatIsUnambiguous();
    }

    public function down(): void
    {
        Schema::table('apps', function (Blueprint $table) {
            $table->dropForeign(['business_profile_id']);
            $table->dropColumn('business_profile_id');
        });
    }

    private function linkWhatIsUnambiguous(): void
    {
        $profiles = DB::table('business_profiles')
            ->get(['id', 'organization_id', 'category'])
            ->keyBy(fn (object $row): string => $row->organization_id.'|'.$row->category);

        foreach (DB::table('apps')->whereNotNull('category')->get(['id', 'organization_id', 'category']) as $app) {
            $match = $profiles->get($app->organization_id.'|'.$app->category);

            if ($match === null) {
                continue;
            }

            DB::table('apps')
                ->where('id', $app->id)
                ->update(['business_profile_id' => $match->id]);
        }
    }
};
