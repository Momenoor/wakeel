<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Keep every email of the inbox" becomes each mailbox's own choice (Mail senders), not one switch for all: a
 * shared mailbox can keep everything while a personal one keeps only what concerns a matter. Where the one
 * switch was on, every mailbox starts on — nothing that was kept stops being kept.
 */
return new class extends Migration
{
    private const OLD_SETTING = 'mail_keep_all_inbox';

    public function up(): void
    {
        Schema::table('mail_senders', function (Blueprint $table) {
            $table->boolean('keep_all_inbox')->default(false)->after('is_active')
                ->comment('every email of this mailbox kept, not only those concerning a matter (others to Unfiled emails)');
        });

        $was = DB::table('settings')->where('key', self::OLD_SETTING)->value('value');

        if (in_array(strtolower(trim((string) $was, '"')), ['1', 'true'], true)) {
            DB::table('mail_senders')->update(['keep_all_inbox' => true]);
        }
    }

    public function down(): void
    {
        Schema::table('mail_senders', function (Blueprint $table) {
            $table->dropColumn('keep_all_inbox');
        });
    }
};
