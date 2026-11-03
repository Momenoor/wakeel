<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A matter with several assistants: its folder is made in the first assistant's OneDrive only, and each of
 * the others gets a shortcut to it in theirs — the row then names whose OneDrive the folder is in (the
 * owner) and the shortcut's own item in the assistant's.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matter_onedrive_folders', function (Blueprint $table) {
            $table->foreignId('owner_party_id')->nullable()->after('party_id')->constrained('parties')->nullOnDelete()->comment('The assistant whose OneDrive holds the folder, when this row is a shortcut to it');
            $table->string('shortcut_item_id')->nullable()->after('drive_item_id')->comment('The shortcut item in this assistant\'s OneDrive');
        });
    }

    public function down(): void
    {
        Schema::table('matter_onedrive_folders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('owner_party_id');
            $table->dropColumn('shortcut_item_id');
        });
    }
};
