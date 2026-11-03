<?php

use App\Models\MatterCreditor;
use Illuminate\Database\Migrations\Migration;

/**
 * A matter's creditors from before the numbers (000226 added the column without filling it) given theirs: after
 * the last number the matter gave, in the order they were added — so the next creditor added follows them, and
 * none is left with "No." empty. The numbers given stay on rolling back: nothing to undo.
 */
return new class extends Migration
{
    public function up(): void
    {
        MatterCreditor::query()->whereNull('number')->distinct()->orderBy('matter_id')->pluck('matter_id')
            ->each(fn ($matterId) => MatterCreditor::numberUnnumbered((int) $matterId));
    }

    public function down(): void {}
};
