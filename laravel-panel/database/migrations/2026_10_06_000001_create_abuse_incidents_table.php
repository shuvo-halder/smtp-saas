<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Provisions the persistent relational abuse incident ledger.
     */
    public function up(): void
    {
        Schema::create('abuse_incidents', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            // Tenant reference (preserves history with nullOnDelete when account is purged)
            $table->foreignId('tenant_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('tenant_email')->nullable();

            // Optional domain reference (preserves history with nullOnDelete)
            $table->foreignId('domain_id')->nullable()->constrained('domains')->nullOnDelete();
            $table->string('domain_name')->nullable();

            // Optional mailbox reference (preserves history with nullOnDelete)
            $table->foreignId('mailbox_id')->nullable()->constrained('mailboxes')->nullOnDelete();
            $table->string('mailbox_email')->nullable();

            // Incident classification & lifecycle
            $table->string('incident_type', 64)->index();
            $table->string('severity', 32)->default('warning')->index();
            $table->string('status', 32)->default('open')->index();
            $table->string('detection_source', 64)->default('log_parser');

            // Human summary & metric observations
            $table->string('summary', 500);
            $table->string('threshold_value', 64)->nullable();
            $table->string('observed_value', 64)->nullable();

            // Structured sanitized evidence (JSON)
            $table->json('evidence')->nullable();

            // Idempotency / deduplication key
            $table->string('idempotency_key', 191)->nullable()->unique();

            // Chronology & resolution tracking
            $table->timestamp('occurred_at')->index();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('resolution_notes')->nullable();

            $table->timestamps();

            // Composite indexes for admin queries and date range filters
            $table->index(['tenant_id', 'created_at']);
            $table->index(['incident_type', 'created_at']);
            $table->index(['status', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     * Drops the abuse_incidents table.
     * Invariant: In production, historical incident ledgers must be archived prior to rollback.
     */
    public function down(): void
    {
        Schema::dropIfExists('abuse_incidents');
    }
};
