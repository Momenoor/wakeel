<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A matter type's own structure of the folder received emails are saved in
 * (inside the matter's OneDrive folder); empty: the one in the OneDrive settings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('types', function (Blueprint $table) {
            $table->string('onedrive_received_emails', 500)->nullable()->comment('Folder structure for received emails, with placeholders; empty: the default');
        });
    }

    public function down(): void
    {
        Schema::table('types', function (Blueprint $table) {
            $table->dropColumn('onedrive_received_emails');
        });
    }
};
