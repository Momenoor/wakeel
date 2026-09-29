<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An event is linked to a matter once. Duplicate links (the table never
 * forbade them) are removed, keeping the first, before the rule is added.
 */
return new class extends Migration
{
    public function up(): void
    {
        $keep = DB::table('calendar_event_matter')
            ->selectRaw('MIN(id) as id')
            ->groupBy('calendar_event_id', 'matter_id')
            ->pluck('id');

        DB::table('calendar_event_matter')->whereNotIn('id', $keep)->delete();

        Schema::table('calendar_event_matter', function (Blueprint $table) {
            $table->unique(['calendar_event_id', 'matter_id']);
        });
    }

    public function down(): void
    {
        Schema::table('calendar_event_matter', function (Blueprint $table) {
            $table->dropUnique(['calendar_event_id', 'matter_id']);
        });
    }
};
