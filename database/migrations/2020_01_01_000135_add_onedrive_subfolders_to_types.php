<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A matter type's own OneDrive folder structure (its subfolders, one per
 * line). Empty: the default one in OneDrive Folders.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('types', function (Blueprint $table) {
            $table->text('onedrive_subfolders')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('types', function (Blueprint $table) {
            $table->dropColumn('onedrive_subfolders');
        });
    }
};
