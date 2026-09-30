<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The mail exactly as it went to this recipient, when it was not built from
 * the campaign's template — an email sent by hand and brought in afterwards
 * (SentMailImporter), where each one differs (the company name …).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bulk_mail_recipients', function (Blueprint $table) {
            $table->string('sent_subject')->nullable()->after('message_id');
            $table->longText('sent_body')->nullable()->after('sent_subject');
        });
    }

    public function down(): void
    {
        Schema::table('bulk_mail_recipients', function (Blueprint $table) {
            $table->dropColumn(['sent_subject', 'sent_body']);
        });
    }
};
