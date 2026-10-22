<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Each user's work account, in one place (Users → Edit): their Microsoft 365 account — for OneDrive, the
 * calendar and their own mailbox — whether that mailbox is read and filed and sent from, and whether they
 * may send from the office's shared mailboxes. A mailbox has an owner (a user's own; none: a shared one)
 * and is read, sent from, or both. An assistant has administrators who handle their matters with them.
 * An assistant's OneDrive account, set before on their party, becomes their user's.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('ms_account')->nullable()->after('email');
            $table->boolean('mailbox_reads')->default(false)->after('ms_account');
            // Read: only what concerns a matter, or every email (the rest waiting in their own Unfiled emails).
            $table->boolean('mailbox_keeps_all')->default(false)->after('mailbox_reads');
            $table->boolean('mailbox_sends')->default(false)->after('mailbox_keeps_all');
            $table->boolean('shared_senders')->default(true)->after('mailbox_sends');
            $table->text('mail_signature')->nullable()->after('shared_senders');
        });

        Schema::table('mail_senders', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->boolean('collects')->default(true)->after('is_active');
            $table->boolean('sends')->default(true)->after('collects');
        });

        Schema::create('assistant_administrators', function (Blueprint $table) {
            $table->id();
            $table->foreignId('party_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['party_id', 'user_id']);
        });

        DB::table('parties')->whereNotNull('user_id')->whereNotNull('onedrive_email')->where('onedrive_email', '!=', '')
            ->select(['user_id', 'onedrive_email'])->orderBy('id')
            ->each(fn ($party) => DB::table('users')->where('id', $party->user_id)->whereNull('ms_account')->update(['ms_account' => $party->onedrive_email]));
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_administrators');

        Schema::table('mail_senders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
            $table->dropColumn(['collects', 'sends']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['ms_account', 'mailbox_reads', 'mailbox_keeps_all', 'mailbox_sends', 'shared_senders', 'mail_signature']);
        });
    }
};
