<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tables for `appforge-admin`, declared here because this repo owns the schema.
 *
 * Two decisions worth defending:
 *
 * **Staff are not customers.** `admin_users` is a separate table from `users`,
 * not a flag on it. A boolean column means one mistaken update — or one missed
 * `where` in a customer-facing query — turns a customer into a superadmin. Two
 * tables means the customer API has no path to staff credentials at all,
 * because it never queries this table.
 *
 * **Every cross-tenant read is logged.** `appforge-admin` exists to look at
 * customer data across organisations; that is its job, not a bug. What makes it
 * safe is that looking is recorded. `audit_logs` is append-only by convention
 * and has no update path in either application.
 */
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
            // Suspending a leaver must not require deleting the row their
            // audit entries point at.
            $table->timestamp('disabled_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->char('id', 30)->primary();
            $table->char('admin_user_id', 30)->nullable();
            // Denormalised on purpose: an audit entry has to stay readable
            // after the staff account is renamed or removed. A join that can
            // return null is not an audit trail.
            $table->string('admin_email')->nullable();
            $table->string('action', 64);
            $table->string('subject_type', 64)->nullable();
            $table->char('subject_id', 30)->nullable();
            // Which customer this touched. The column most queries filter on
            // when answering "who looked at us?".
            $table->char('organization_id', 30)->nullable();
            $table->json('context')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['organization_id', 'created_at']);
            $table->index(['admin_user_id', 'created_at']);
            $table->index(['subject_type', 'subject_id']);

            // No foreign keys. An audit row must survive the deletion of
            // everything it refers to — that is precisely when it matters.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('admin_users');
    }
};
