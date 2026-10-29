<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mailbox folders (Settings → Mailbox folders, the super administrator's): the folder a mailbox is read from for
 * the matters' emails instead of its Inbox, each of its folders forwarded on to an address of its own — a mailbox
 * whose own filters can only move an email (Yahoo) forwarding with conditions after all — and every email
 * forwarded so, once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mail_senders', function (Blueprint $table) {
            $table->string('inbox_folder', 255)->nullable()->after('files_forwards')
                ->comment('The IMAP folder read for the matters\' emails; empty: the Inbox');
            $table->boolean('folders_only')->default(false)->after('inbox_folder')
                ->comment('A mailbox of Mailbox folders alone: not among the mail senders, never sent from nor shared');
        });

        Schema::create('mailbox_folder_forwards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mail_sender_id')->constrained('mail_senders')->cascadeOnDelete();
            $table->string('folder', 255)->comment('the folder\'s whole path, as the mailbox names it');
            $table->string('forward_to', 255)->comment('the address its emails are forwarded to');
            $table->unsignedBigInteger('last_uid')->default(0)->comment('the last email (IMAP UID) gone on from it: those after it are new');
            $table->boolean('is_active')->default(true);
            $table->text('error')->nullable()->comment('why the last run could not read or forward it');
            $table->timestamps();
            $table->unique(['mail_sender_id', 'folder']);
        });

        Schema::create('mailbox_forwarded_emails', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mail_sender_id')->constrained('mail_senders')->cascadeOnDelete();
            $table->string('folder', 255);
            $table->string('message_id', 191);
            $table->string('subject', 500)->nullable();
            $table->string('from', 255)->nullable();
            $table->timestamp('at')->nullable()->comment('when the email came');
            $table->string('forwarded_to', 255);
            $table->timestamp('forwarded_at')->nullable()->comment('empty: not gone yet (see error)');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->text('error')->nullable();
            $table->timestamps();
            $table->index(['mail_sender_id', 'message_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mailbox_forwarded_emails');
        Schema::dropIfExists('mailbox_folder_forwards');

        Schema::table('mail_senders', function (Blueprint $table) {
            $table->dropColumn(['inbox_folder', 'folders_only']);
        });
    }
};
