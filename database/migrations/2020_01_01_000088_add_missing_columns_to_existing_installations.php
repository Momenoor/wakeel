<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Brings installations created before these columns existed up to the
 * current schema. `installments.is_vat_only` was added to the consolidated
 * create_installments_table migration after live databases had already run
 * it, so `migrate` alone would never add it there; the licenses columns
 * repeat 000087 so this one migration covers everything an existing
 * database lacks.
 *
 * Additive only, and every column is checked first — on a fresh install
 * (where the create migrations already made them) this does nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('installments', 'is_vat_only')) {
            Schema::table('installments', function (Blueprint $table) {
                $table->boolean('is_vat_only')->default(false)->after('is_security_deposit');
            });
        }

        $licenseColumns = [
            'latest_version' => fn (Blueprint $table) => $table->string('latest_version')->nullable()->comment('Newest published release, as last reported by the license server.'),
            'latest_release_notes' => fn (Blueprint $table) => $table->text('latest_release_notes')->nullable(),
            'latest_released_at' => fn (Blueprint $table) => $table->timestamp('latest_released_at')->nullable(),
        ];

        foreach ($licenseColumns as $column => $definition) {
            if (! Schema::hasColumn('licenses', $column)) {
                Schema::table('licenses', $definition);
            }
        }
    }

    /**
     * Nothing to undo: on an existing installation these columns are now
     * part of the schema its code expects, and on a fresh one this
     * migration added nothing.
     */
    public function down(): void
    {
        //
    }
};
