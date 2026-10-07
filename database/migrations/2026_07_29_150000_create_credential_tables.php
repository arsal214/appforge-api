<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The credential vault.
 *
 * These rows hold, in encrypted form, the keys to a customer's shop. A breach
 * here is not "an incident" — it is other people's businesses. The schema is
 * shaped accordingly.
 *
 * Note what is *not* stored: nothing in plaintext except a hint (last four
 * characters) and a label. The panel is built so that a credential can be set
 * and replaced but never read back, because nobody in the panel ever needs to
 * see one, and a feature that displays a secret is a feature that eventually
 * screenshots one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_credentials', function (Blueprint $table) {
            $table->char('id', 30)->primary();
            $table->char('app_id', 30);
            // Denormalised so an access log can be filtered by tenant without a
            // join through a table that may have been deleted.
            $table->char('organization_id', 30);

            // woocommerce, shopify, apns, fcm — a closed vocabulary.
            $table->string('type', 32);

            // The secret, sealed. Base64 of IV + auth tag + ciphertext.
            $table->text('ciphertext');
            // The data key that opens it, itself wrapped by the KMS master key.
            $table->text('wrapped_key');
            // Which key management wrapped it, so a migration between drivers
            // can tell what it is looking at instead of guessing.
            $table->string('key_provider', 32);

            // Safe to display: "ends in 4f2a", "Live store". Never the secret.
            $table->string('hint', 64)->nullable();
            $table->string('label', 120)->nullable();

            $table->timestamp('last_used_at')->nullable();
            $table->char('created_by', 30)->nullable();
            $table->timestamps();

            $table->foreign('app_id')
                ->references('id')->on('apps')
                ->cascadeOnDelete();

            // One credential of each type per app. Replacing rotates in place
            // rather than accumulating old secrets nobody remembers exist.
            $table->unique(['app_id', 'type']);
        });

        Schema::create('credential_accesses', function (Blueprint $table) {
            $table->char('id', 30)->primary();

            // No foreign key, on purpose. This record has to outlive the
            // credential, the app and the organisation it refers to — an audit
            // trail that cascades away with its subject is not an audit trail.
            $table->char('credential_id', 30)->nullable();
            $table->char('app_id', 30)->nullable();
            $table->char('organization_id', 30)->nullable();
            $table->string('type', 32)->nullable();

            // created, rotated, revealed, reveal_failed, deleted.
            // `revealed` is the one that matters: a write is visible in the
            // panel, a read is invisible unless it is recorded here.
            $table->string('action', 32);

            // Who or what. A panel user, a build worker, a proxy request.
            $table->string('actor_type', 32);
            $table->char('actor_id', 30)->nullable();
            // Why the secret was needed. A reveal with no stated purpose is a
            // reveal nobody can review later.
            $table->string('purpose', 120)->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['organization_id', 'created_at']);
            $table->index(['credential_id', 'created_at']);
            $table->index(['action', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credential_accesses');
        Schema::dropIfExists('app_credentials');
    }
};
