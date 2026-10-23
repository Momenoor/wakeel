<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a reminder item in a person's calendar says (a fingerprint of its subject and text): an event renamed,
 * moved or relocated has its items rewritten, not only moved (CalendarAlarms).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calendar_event_alerts', function (Blueprint $table) {
            $table->string('item_hash', 40)->nullable()->after('outlook_item_id')
                ->comment('sha1 of the subject and text its reminder item was given; empty: made before it was kept');
        });
    }

    public function down(): void
    {
        Schema::table('calendar_event_alerts', function (Blueprint $table) {
            $table->dropColumn('item_hash');
        });
    }
};
