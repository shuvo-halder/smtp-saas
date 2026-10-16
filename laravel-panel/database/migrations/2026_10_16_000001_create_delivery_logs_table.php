<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Provisions the persistent delivery activity logs table.
     */
    public function up(): void
    {
        Schema::create('delivery_logs', function (Blueprint $table) {
            $table->id();

            // Tenant reference (preserves history with nullOnDelete when account is purged)
            $table->foreignId('tenant_id')->nullable()->constrained('users')->nullOnDelete();

            // Domain reference
            $table->foreignId('domain_id')->nullable()->constrained('domains')->nullOnDelete();

            // Mailbox reference
            $table->foreignId('mailbox_id')->nullable()->constrained('mailboxes')->nullOnDelete();

            // Message metadata & identities
            $table->string('sender_email', 255)->index();
            $table->string('recipient_email', 255)->index();
            $table->string('queue_id', 64)->nullable()->index();
            $table->string('message_id', 255)->nullable()->index();

            // Outcome & status: delivered, deferred, bounced, rejected, accepted
            $table->string('status', 32)->index();
            $table->string('dsn_code', 32)->nullable();
            $table->unsignedSmallInteger('smtp_code')->nullable();

            // Sanitized MTA response text or rejection reason
            $table->text('response_text')->nullable();

            // Chronology
            $table->timestamp('occurred_at')->index();
            $table->timestamps();

            // Composite indexes for tenant queries and activity timeline filtering
            $table->index(['tenant_id', 'occurred_at']);
            $table->index(['tenant_id', 'status', 'occurred_at']);
            $table->index(['mailbox_id', 'occurred_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('delivery_logs');
    }
};
