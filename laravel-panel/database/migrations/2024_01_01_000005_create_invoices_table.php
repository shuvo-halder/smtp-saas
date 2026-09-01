<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// =============================================================================
// Migration: Create invoices & subscriptions table
// =============================================================================
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained();
            $table->string('invoice_number')->unique();
            $table->enum('billing_cycle', ['monthly', 'yearly']);
            $table->decimal('subtotal', 10, 2);
            $table->decimal('tax', 10, 2)->default(0);
            $table->decimal('total', 10, 2);
            $table->string('currency', 3)->default('BDT');
            $table->enum('status', ['pending', 'paid', 'failed', 'cancelled', 'refunded'])
                  ->default('pending');

            // Payment gateway info
            $table->string('payment_gateway')->nullable();   // sslcommerz, stripe
            $table->string('transaction_id')->nullable();
            $table->string('gateway_transaction_id')->nullable();
            $table->json('payment_response')->nullable();    // Full gateway response
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('due_date');

            // Period this invoice covers
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
