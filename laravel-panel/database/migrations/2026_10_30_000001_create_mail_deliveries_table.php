<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Provisions the transactional mail delivery queue & lifecycle tracking table.
     */
    public function up(): void
    {
        Schema::create('mail_deliveries', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            // Tenant context (nullable for system-level notifications)
            $table->foreignId('tenant_id')->nullable()->constrained('users')->cascadeOnDelete();

            // Addressing & template mapping
            $table->string('recipient_email', 255)->index();
            $table->string('message_type', 50)->index();
            $table->string('template_id', 50)->index();
            $table->string('subject', 255);

            // Sanitized payload (sensitive tokens/passwords masked or purged)
            $table->json('payload')->nullable();

            // Delivery lifecycle status: queued, processing, sent, failed, cancelled
            $table->string('status', 20)->default('queued')->index();

            // Retry tracking
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedTinyInteger('max_attempts')->default(3);

            // Deduplication & idempotency
            $table->string('idempotency_key', 255)->nullable()->index();

            // Operational timestamps
            $table->timestamp('last_attempted_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            // Sanitized failure diagnostic
            $table->text('last_error')->nullable();

            // Dry-run mode flag (indicates delivery was simulated without external network traffic)
            $table->boolean('is_dry_run')->default(false);

            $table->timestamps();

            // Composite indexes for fast tenant filtering & queue worker status queries
            $table->index(['tenant_id', 'created_at']);
            $table->index(['tenant_id', 'status', 'created_at']);
            $table->index(['status', 'created_at']);
            $table->index(['status', 'last_attempted_at']);
            $table->index(['tenant_id', 'idempotency_key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mail_deliveries');
    }
};
