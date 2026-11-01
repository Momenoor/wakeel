<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where the files a received email came with are saved, apart from its PDF and original — for a type's matters:
 * in general, and for each side (the plaintiff's, the defendant's, the creditors'). Empty: beside the PDF.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('types', function (Blueprint $table) {
            $table->string('onedrive_attachments', 500)->nullable()->after('onedrive_received_creditors')->comment('Folder for the files received emails came with, with placeholders; empty: beside the email\'s PDF');
            $table->string('onedrive_attachments_plaintiff', 500)->nullable()->after('onedrive_attachments');
            $table->string('onedrive_attachments_defendant', 500)->nullable()->after('onedrive_attachments_plaintiff');
            $table->string('onedrive_attachments_creditors', 500)->nullable()->after('onedrive_attachments_defendant');
            $table->string('onedrive_sent_creditors', 500)->nullable()->after('onedrive_sent_emails')->comment('Folder for the emails sent to a creditor, with placeholders; empty: the sent emails folder');
        });
    }

    public function down(): void
    {
        Schema::table('types', function (Blueprint $table) {
            $table->dropColumn(['onedrive_attachments', 'onedrive_attachments_plaintiff', 'onedrive_attachments_defendant', 'onedrive_attachments_creditors', 'onedrive_sent_creditors']);
        });
    }
};
