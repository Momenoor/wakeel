<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who joined a meeting's Teams call, and when each joined and left — Teams' attendance report, read after the
 * meeting (TeamsAttendance): kept on its minutes, and on a Teams meeting made in Wakeel's calendar with none.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['matter_minutes' => 'attendees', 'calendar_events' => 'online_meeting_url'] as $table => $after) {
            Schema::table($table, function (Blueprint $table) use ($after) {
                $table->json('attendance')->nullable()->after($after)->comment("Teams' attendance report: [{name, email, role, joined_at, left_at, seconds, intervals, party_id}]");
                $table->timestamp('attendance_read_at')->nullable()->after('attendance');
            });
        }
    }

    public function down(): void
    {
        foreach (['matter_minutes', 'calendar_events'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropColumn(['attendance', 'attendance_read_at']);
            });
        }
    }
};
