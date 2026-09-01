<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// =============================================================================
// Migration: Create mailboxes table
// =============================================================================
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mailboxes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('domain_id')->constrained()->cascadeOnDelete();
            $table->string('local_part');                    // "info" from info@example.com
            $table->string('email')->unique();               // full email address
            $table->string('display_name')->nullable();
            $table->integer('quota_mb')->default(1024);      // Storage quota in MB
            $table->boolean('is_active')->default(true);
            $table->boolean('is_catchall')->default(false);  // Catch-all mailbox flag
            $table->integer('server_user_id')->nullable();   // ID in virtual_users table
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['domain_id', 'local_part']);
            $table->index(['domain_id', 'is_active']);
        });

        // Email aliases (forwarding)
        Schema::create('email_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('domain_id')->constrained()->cascadeOnDelete();
            $table->string('source_email');                  // alias@example.com
            $table->string('destination_email');             // real@example.com
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['domain_id', 'source_email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_aliases');
        Schema::dropIfExists('mailboxes');
    }
};
