<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Adds worker fencing claim_token to mail_deliveries table.
     */
    public function up(): void
    {
        Schema::table('mail_deliveries', function (Blueprint $table) {
            $table->string('claim_token', 64)->nullable()->after('idempotency_key')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('mail_deliveries', function (Blueprint $table) {
            $table->dropIndex(['claim_token']);
            $table->dropColumn('claim_token');
        });
    }
};
