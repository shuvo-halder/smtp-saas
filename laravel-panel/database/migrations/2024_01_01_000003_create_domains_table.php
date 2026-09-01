<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// =============================================================================
// Migration: Create domains table
// =============================================================================
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('domains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('domain_name')->unique();

            // Domain verification status
            $table->enum('status', ['pending', 'verifying', 'active', 'suspended', 'failed'])
                  ->default('pending');

            // DNS record verification flags
            $table->boolean('mx_verified')->default(false);
            $table->boolean('spf_verified')->default(false);
            $table->boolean('dkim_verified')->default(false);
            $table->boolean('dmarc_verified')->default(false);

            // DKIM public key (stored for display in dashboard)
            $table->text('dkim_public_key')->nullable();
            $table->string('dkim_selector')->default('default');

            // Server-side tracking
            $table->integer('server_domain_id')->nullable(); // ID in virtual_domains table
            $table->timestamp('last_dns_check_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domains');
    }
};
