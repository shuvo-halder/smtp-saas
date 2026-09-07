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
        Schema::create('tenant_outbound_usage', function (Blueprint $table) {
            $table->id();
            // Tenant ownership (User)
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('usage_date');
            $table->integer('recipient_count')->default(0);
            $table->timestamps();
            
            // Unique constraint to prevent duplicate daily records per tenant
            $table->unique(['user_id', 'usage_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenant_outbound_usage');
    }
};
