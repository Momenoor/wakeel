<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Each employee's net pay rounded up to the whole dirham — on runs made from now on (PayrollRun::$attributes):
 * the runs there are keep their figures, generated again or not. What it adds is kept on the payslip.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_runs', function (Blueprint $table) {
            $table->boolean('round_net_up')->default(false)->after('status')->comment('Net pay rounded up to the whole dirham');
        });

        Schema::table('payslips', function (Blueprint $table) {
            $table->decimal('rounding', 12, 2)->default(0)->after('total_deductions')->comment('Added to round the net pay up to the whole dirham');
        });
    }

    public function down(): void
    {
        Schema::table('payslips', function (Blueprint $table) {
            $table->dropColumn('rounding');
        });

        Schema::table('payroll_runs', function (Blueprint $table) {
            $table->dropColumn('round_net_up');
        });
    }
};
