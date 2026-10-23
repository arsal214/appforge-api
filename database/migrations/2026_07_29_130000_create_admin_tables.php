<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_users', function (Blueprint $table) {
            $table->char('id', 30)->primary();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->rememberToken();

            $table->timestamp('disabled_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->char('id', 30)->primary();
            $table->char('admin_user_id', 30)->nullable();

            $table->string('admin_email')->nullable();
            $table->string('action', 64);
            $table->string('subject_type', 64)->nullable();
            $table->char('subject_id', 30)->nullable();

            $table->char('organization_id', 30)->nullable();
            $table->json('context')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['organization_id', 'created_at']);
            $table->index(['admin_user_id', 'created_at']);
            $table->index(['subject_type', 'subject_id']);

        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('admin_users');
    }
};
