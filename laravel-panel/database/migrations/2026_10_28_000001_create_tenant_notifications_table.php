<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('tenant_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('users')->cascadeOnDelete();
            $table->string('category', 50)->index(); // 'domain_dns', 'security_abuse', 'smtp_quota', 'billing_subscription', 'mailbox_rule', 'system'
            $table->string('severity', 20)->default('info')->index(); // 'info', 'warning', 'critical', 'success'
            $table->string('title', 255);
            $table->text('message');
            $table->string('action_url', 255)->nullable();
            $table->string('deduplication_key', 255)->nullable();
            $table->json('data')->nullable();
            $table->timestamp('read_at')->nullable()->index();
            $table->timestamp('dismissed_at')->nullable()->index();
            $table->timestamps();

            // Composite indexes for high-frequency queries
            $table->index(['tenant_id', 'read_at', 'created_at'], 'idx_tenant_read_created');
            $table->index(['tenant_id', 'category', 'created_at'], 'idx_tenant_cat_created');
            $table->index(['tenant_id', 'deduplication_key'], 'idx_tenant_dedup');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenant_notifications');
    }
};
