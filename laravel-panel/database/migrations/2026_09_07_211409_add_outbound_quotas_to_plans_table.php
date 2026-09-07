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
        Schema::table('plans', function (Blueprint $table) {
            $table->integer('daily_outbound_recipients')->default(-1)->after('max_aliases_per_domain')->comment('-1 means unlimited, 0 means disabled, positive is finite daily quota');
            $table->integer('mailbox_daily_outbound_recipients')->default(-1)->after('daily_outbound_recipients')->comment('-1 means unlimited, 0 means disabled, positive is finite daily quota');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn(['daily_outbound_recipients', 'mailbox_daily_outbound_recipients']);
        });
    }
};
