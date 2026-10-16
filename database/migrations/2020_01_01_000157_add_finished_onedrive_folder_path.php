<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where an assistant's folders of finished matters are moved to in their
 * OneDrive, and when (and why not) each matter's folder was.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parties', function (Blueprint $table) {
            $table->string('onedrive_finished_path')->nullable()->comment('Folder in their OneDrive that finished matters folders are moved to; empty: not moved');
        });

        Schema::table('matter_onedrive_folders', function (Blueprint $table) {
            $table->timestamp('moved_at')->nullable()->comment('Moved to the finished matters folder');
            $table->text('move_error')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('matter_onedrive_folders', function (Blueprint $table) {
            $table->dropColumn(['moved_at', 'move_error']);
        });

        Schema::table('parties', function (Blueprint $table) {
            $table->dropColumn('onedrive_finished_path');
        });
    }
};
