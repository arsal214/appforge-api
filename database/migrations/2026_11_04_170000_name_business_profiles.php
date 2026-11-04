<?php

use AppForge\Schema\Contract;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_profiles', function (Blueprint $table) {
            $table->string('name', Contract::limit('maxLabel'))->nullable()->after('category');
        });

        $this->nameWhatIsAlreadyThere();

        Schema::table('business_profiles', function (Blueprint $table) {
            $table->string('name', Contract::limit('maxLabel'))->nullable(false)->change();
        });

        Schema::table('business_profiles', function (Blueprint $table) {
            $table->unique(['organization_id', 'category', 'name']);
        });

        Schema::table('business_profiles', function (Blueprint $table) {
            $table->dropUnique(['organization_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::table('business_profiles', function (Blueprint $table) {
            $table->dropUnique(['organization_id', 'category', 'name']);
            $table->unique(['organization_id', 'category']);
            $table->dropColumn('name');
        });
    }

    private function nameWhatIsAlreadyThere(): void
    {
        foreach (DB::table('business_profiles')->get(['id', 'category', 'data']) as $row) {
            $data = json_decode((string) $row->data, true);
            $businessName = is_array($data) ? trim((string) ($data['businessName'] ?? '')) : '';

            $name = $businessName !== ''
                ? mb_substr($businessName, 0, Contract::limit('maxLabel'))
                : ucfirst(str_replace('_', ' ', $row->category));

            DB::table('business_profiles')->where('id', $row->id)->update(['name' => $name]);
        }
    }
};
