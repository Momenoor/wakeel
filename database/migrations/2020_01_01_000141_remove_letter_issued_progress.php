<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A letter issued is no step of a matter's progress — only its sending is.
 * Those recorded by Wakeel go; one added by hand (no source) stays.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('matter_progress')
            ->where('type', 'letter_issued')
            ->whereNotNull('source_type')
            ->delete();
    }

    public function down(): void
    {
        // Not brought back: MatterProgressRecorder no longer records them.
    }
};
