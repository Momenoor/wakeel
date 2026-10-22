<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A custom field can be shown beside a matter's number wherever matters are picked or searched (its phase,
 * say), to tell apart matters of one number. The phase's field starts shown.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matter_field_definitions', function (Blueprint $table) {
            $table->boolean('show_with_number')->default(false)->after('show_in_supplementary')->comment('Shown beside the matter number where matters are picked or searched');
        });

        DB::table('matter_field_definitions')->whereIn('label', ['المرحلة', 'المرحله', 'Phase', 'Stage'])->update(['show_with_number' => true]);
    }

    public function down(): void
    {
        Schema::table('matter_field_definitions', function (Blueprint $table) {
            $table->dropColumn('show_with_number');
        });
    }
};
