<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Deleting a property used to leave its units behind. Those units go now,
 * dated with their property — except any a lease or quotation still names,
 * which stay as that record's history.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('properties')
            ->whereNotNull('deleted_at')
            ->select(['id', 'deleted_at'])
            ->orderBy('id')
            ->each(function (object $property): void {
                DB::table('units')
                    ->where('property_id', $property->id)
                    ->whereNull('deleted_at')
                    ->whereNotExists(fn ($query) => $query->from('lease_unit')->whereColumn('lease_unit.unit_id', 'units.id'))
                    ->whereNotExists(fn ($query) => $query->from('quotation_unit')->whereColumn('quotation_unit.unit_id', 'units.id'))
                    ->update(['deleted_at' => $property->deleted_at]);

                DB::table('properties')->where('id', $property->id)->update([
                    'total_units' => DB::table('units')->where('property_id', $property->id)->whereNull('deleted_at')->count(),
                ]);
            });
    }

    public function down(): void
    {
        // Nothing to undo: restoring a property brings its units back.
    }
};
