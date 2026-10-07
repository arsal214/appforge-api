<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('apps', function (Blueprint $table) {
            $table->char('id', 30)->primary();
            $table->char('organization_id', 30);
            $table->string('name');
            $table->string('slug');
            $table->string('source_url')->nullable();
            // The template is code, not a row: `tpl_ecommerce` keys into
            // `templateConfigs` in @appforge/components. A foreign key to a
            // templates table would let the database hold a template the
            // renderer has never heard of.
            $table->string('template_id', 64);
            $table->string('industry', 32);
            $table->string('status', 32)->default('draft');
            $table->char('accent_color', 7);
            // Null until a build compiles the first immutable app_versions row.
            $table->unsignedInteger('published_version')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('organization_id')
                ->references('id')->on('organizations')
                ->cascadeOnDelete();

            // Covers the only list query there is: this tenant's apps, newest
            // first. The tenant column leads because every query is scoped.
            $table->index(['organization_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('apps');
    }
};
