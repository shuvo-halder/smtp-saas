<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// =============================================================================
// Migration: Create plans table
// =============================================================================
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');                          // Basic, Pro, Business
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->integer('max_domains');                  // -1 = unlimited
            $table->integer('max_mailboxes_per_domain');     // -1 = unlimited
            $table->integer('storage_mb_per_mailbox');       // Storage per mailbox
            $table->integer('max_aliases_per_domain')->default(10);
            $table->decimal('price_monthly', 10, 2);
            $table->decimal('price_yearly', 10, 2);
            $table->json('features')->nullable();            // Additional features list
            $table->boolean('is_active')->default(true);
            $table->boolean('is_featured')->default(false);  // Highlight on pricing page
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
