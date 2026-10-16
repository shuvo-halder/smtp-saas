<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mailboxes', function (Blueprint $table) {
            // Inbound Mailbox Forwarding Rules
            $table->boolean('is_forwarding_enabled')->default(false)->after('is_catchall');
            $table->text('forwarding_destinations')->nullable()->after('is_forwarding_enabled');
            $table->boolean('forwarding_keep_copy')->default(true)->after('forwarding_destinations');

            // Mailbox Auto-Reply (Vacation / Out-of-Office)
            $table->boolean('auto_reply_enabled')->default(false)->after('forwarding_keep_copy');
            $table->string('auto_reply_subject', 255)->nullable()->after('auto_reply_enabled');
            $table->text('auto_reply_body')->nullable()->after('auto_reply_subject');
            $table->timestamp('auto_reply_start_at')->nullable()->after('auto_reply_body');
            $table->timestamp('auto_reply_end_at')->nullable()->after('auto_reply_start_at');
        });
    }

    public function down(): void
    {
        Schema::table('mailboxes', function (Blueprint $table) {
            $table->dropColumn([
                'is_forwarding_enabled',
                'forwarding_destinations',
                'forwarding_keep_copy',
                'auto_reply_enabled',
                'auto_reply_subject',
                'auto_reply_body',
                'auto_reply_start_at',
                'auto_reply_end_at',
            ]);
        });
    }
};
