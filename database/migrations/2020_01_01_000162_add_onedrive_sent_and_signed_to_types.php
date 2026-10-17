<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A matter type's own folders, inside the matter's OneDrive folder, for the emails
 * sent and the minutes signed; empty: the ones in the OneDrive settings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('types', function (Blueprint $table) {
            $table->string('onedrive_sent_emails', 500)->nullable()->comment('Folder for sent emails, with placeholders; empty: the default');
            $table->string('onedrive_signed_minutes', 500)->nullable()->comment('Folder for signed minutes, with placeholders; empty: the default');
        });
    }

    public function down(): void
    {
        Schema::table('types', function (Blueprint $table) {
            $table->dropColumn(['onedrive_sent_emails', 'onedrive_signed_minutes']);
        });
    }
};
