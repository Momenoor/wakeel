<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The files of a letter's or minutes' email too large to attach, put in a folder of the matter's OneDrive folder
 * and shared by link (SharedAttachments): the link, the folder and the files' names, remembered so the link can
 * be had before sending, to copy into the email or the letter, and the send reuses it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matter_letters', function (Blueprint $table) {
            $table->json('shared_files')->nullable()->after('sent_at')->comment('Files sent as a link: {url, anyone, folder, names, bytes}');
        });
        Schema::table('matter_minutes', function (Blueprint $table) {
            $table->json('shared_files')->nullable()->after('live_file')->comment('Files sent as a link: {url, anyone, folder, names, bytes}');
        });
    }

    public function down(): void
    {
        Schema::table('matter_letters', fn (Blueprint $table) => $table->dropColumn('shared_files'));
        Schema::table('matter_minutes', fn (Blueprint $table) => $table->dropColumn('shared_files'));
    }
};
