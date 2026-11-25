<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a calculation settles what is waiting from previous periods (paid incentive taken back or added after its
 * calculation was finalized: IncentiveReversals), or leaves it pending for an upcoming calculation. On by default:
 * as every calculation did before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incentive_calculations', function (Blueprint $table) {
            $table->boolean('apply_previous_adjustments')->default(true)->after('status')
                ->comment('settle what is waiting from previous periods here (IncentiveReversals), or keep it pending for an upcoming calculation');
        });
    }

    public function down(): void
    {
        Schema::table('incentive_calculations', function (Blueprint $table) {
            $table->dropColumn('apply_previous_adjustments');
        });
    }
};
