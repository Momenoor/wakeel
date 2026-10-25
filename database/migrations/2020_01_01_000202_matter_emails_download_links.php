<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The links an email that came in brings its documents by (EmailDownloadLinks) — shown on the matter's Emails
 * tab, for one that sent them as links instead of attaching them. Null: not read for them (an email of before).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matter_emails', function (Blueprint $table) {
            $table->json('links')->nullable()->after('files')->comment('Links to documents to download: [{url, label}]; null: not read');
        });
    }

    public function down(): void
    {
        Schema::table('matter_emails', function (Blueprint $table) {
            $table->dropColumn('links');
        });
    }
};
