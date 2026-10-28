<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When Teams took a meeting made in the system as asked — recording by itself, its co-organizers
 * (OutlookCalendarService::prepare()); until then asked again every quarter of an hour (calendar:ready-teams).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calendar_events', function (Blueprint $table) {
            $table->timestamp('teams_ready_at')->nullable()->after('online_meeting_url');
        });
    }

    public function down(): void
    {
        Schema::table('calendar_events', function (Blueprint $table) {
            $table->dropColumn('teams_ready_at');
        });
    }
};
