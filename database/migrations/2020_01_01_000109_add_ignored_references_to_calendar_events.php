<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Matter numbers written in an event's title that are not that matter for
 * this event ("21/2026" meaning something else) — never linked from the
 * title, never reported as a missing matter.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calendar_events', function (Blueprint $table) {
            $table->json('ignored_references')->nullable()->after('title');
        });
    }

    public function down(): void
    {
        Schema::table('calendar_events', function (Blueprint $table) {
            $table->dropColumn('ignored_references');
        });
    }
};
