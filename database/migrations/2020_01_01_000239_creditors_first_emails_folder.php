<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A matter's first mails to its creditors-to-be: the emails sent marked so — a bulk mail campaign ticked "First mail
 * to creditors", or a sent email marked on the Emails tab — saved all in one folder (a type's, else the setting
 * onedrive_first_to_creditors_folder), each named as set (first_mail_name, else onedrive_first_mail_name); the day
 * one went to someone is their "First email sent" on the Contacts tab.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('types', function (Blueprint $table) {
            $table->string('onedrive_first_to_creditors', 500)->nullable()->after('onedrive_sent_creditors')->comment('Folder the first mails to creditors are saved in, all in one; empty: the setting');
            $table->string('first_mail_name', 500)->nullable()->after('onedrive_first_to_creditors')->comment('Name pattern of a first mail to a creditor ({{name}} …); empty: the setting');
        });

        Schema::table('matter_emails', function (Blueprint $table) {
            $table->boolean('first_mail')->default(false)->after('direction')->comment('A sent email marked as the first mail to a creditor (CreditorFirstMails)');
        });

        Schema::table('bulk_mail_campaigns', function (Blueprint $table) {
            $table->boolean('first_mail')->default(false)->after('matter_id')->comment('Its emails are first mails to creditors: saved in the first mails folder');
        });
    }

    public function down(): void
    {
        Schema::table('bulk_mail_campaigns', function (Blueprint $table) {
            $table->dropColumn('first_mail');
        });

        Schema::table('matter_emails', function (Blueprint $table) {
            $table->dropColumn('first_mail');
        });

        Schema::table('types', function (Blueprint $table) {
            $table->dropColumn(['onedrive_first_to_creditors', 'first_mail_name']);
        });
    }
};
