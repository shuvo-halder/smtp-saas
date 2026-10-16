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
        Schema::table('domains', function (Blueprint $table) {
            $table->string('catch_all_policy', 32)->default('disabled')->after('activated_at');
            $table->foreignId('catch_all_mailbox_id')
                ->nullable()
                ->after('catch_all_policy')
                ->constrained('mailboxes')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->dropForeign(['catch_all_mailbox_id']);
            $table->dropColumn(['catch_all_mailbox_id', 'catch_all_policy']);
        });
    }
};
