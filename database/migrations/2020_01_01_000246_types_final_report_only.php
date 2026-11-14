<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A matter type whose matters have one report: no Review Report request for an initial one, and the
 * Confirm Report request, approved, sets the initial report, the final report and its memo date together.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('types', function (Blueprint $table) {
            $table->boolean('final_report_only')->default(false)->after('exclude_from_incentive_count');
        });
    }

    public function down(): void
    {
        Schema::table('types', function (Blueprint $table) {
            $table->dropColumn('final_report_only');
        });
    }
};
