<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What of an email that came in is in the matter's OneDrive: its PDF, its
 * original (.eml), and how many of the files it came with — {pdf, eml,
 * attachments, checked_at}; a missing key is not known.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matter_emails', function (Blueprint $table) {
            $table->json('onedrive')->nullable()->comment('{pdf, eml, attachments, checked_at}: what is in the matter\'s OneDrive');
        });

        // What the earlier flag said: only the original was ever recorded.
        DB::table('matter_emails')->where('eml_saved', true)->update(['onedrive' => json_encode(['eml' => true])]);

        Schema::table('matter_emails', function (Blueprint $table) {
            $table->dropColumn('eml_saved');
        });
    }

    public function down(): void
    {
        Schema::table('matter_emails', function (Blueprint $table) {
            $table->boolean('eml_saved')->default(false);
        });

        Schema::table('matter_emails', function (Blueprint $table) {
            $table->dropColumn('onedrive');
        });
    }
};
