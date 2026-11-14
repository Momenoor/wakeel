<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bulk mail: a recipient whose send failed waits before it is tried again (next_attempt_at, by
 * mail_senders.retry_delay_minutes) instead of being tried every minute; and a campaign the system paused itself
 * says why (paused_reason — its mailbox deactivated or deleted).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bulk_mail_recipients', function (Blueprint $table) {
            $table->timestamp('next_attempt_at')->nullable()->after('attempt_count');
        });

        Schema::table('bulk_mail_campaigns', function (Blueprint $table) {
            $table->text('paused_reason')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('bulk_mail_recipients', function (Blueprint $table) {
            $table->dropColumn('next_attempt_at');
        });

        Schema::table('bulk_mail_campaigns', function (Blueprint $table) {
            $table->dropColumn('paused_reason');
        });
    }
};
