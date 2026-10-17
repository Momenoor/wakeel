<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Replies collected for a matter's emails were kept with no status, so the matter's
 * Emails tab did not list them: they are filed on their matter, like any email from
 * its parties.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('matter_emails')
            ->where('direction', 'received')
            ->whereNull('status')
            ->whereNotNull('matter_id')
            ->update(['status' => 'filed']);
    }

    public function down(): void
    {
        // Nothing to undo: a filed reply is still a reply.
    }
};
