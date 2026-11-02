<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The file from the matter's OneDrive folders the office chose on Record the meeting to show over the minutes
 * on every live view — its folder, item and name — until it is hidden.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matter_minutes', function (Blueprint $table) {
            $table->json('live_file')->nullable()->after('ended_at')->comment('The OneDrive file shown over the minutes on the live view: {folder, item, name}; none: the minutes alone');
        });
    }

    public function down(): void
    {
        Schema::table('matter_minutes', function (Blueprint $table) {
            $table->dropColumn('live_file');
        });
    }
};
