<?php

use App\Http\Controllers\ClientErrorController;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A background request with no answer at once (status 0: a page closed or asleep mid-request, the line or Pusher
 * dropped for a moment) is no longer a warning on Settings → Error tracking (ClientErrorController): those still
 * unresolved from before are marked dealt with, so the screen shows what is worth looking into. They stay on the
 * Performance page. One that waited long for its answer (the server hung) is kept.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('error_reports')
            ->where('level', 'warning')
            ->where('exception', 'Browser request failed')
            ->whereNull('status')
            ->whereNull('resolved_at')
            ->get(['id', 'input'])
            ->filter(fn (object $row): bool => (int) (json_decode((string) $row->input, true)['ms'] ?? 0) < ClientErrorController::HUNG_MS)
            ->pluck('id')
            ->chunk(500)
            ->each(fn ($ids) => DB::table('error_reports')->whereIn('id', $ids->all())->update(['resolved_at' => now()]));
    }

    public function down(): void
    {
        // Not put back: they were noise.
    }
};
