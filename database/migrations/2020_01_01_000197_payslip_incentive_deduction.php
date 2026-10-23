<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An incentive calculation's deductions (paid shares taken back, a fixed deduction) are taken from its incentive
 * first, and what the incentive does not cover from the salary of the month it closes in, once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payslips', function (Blueprint $table) {
            $table->decimal('incentive_deduction', 12, 2)->default(0)->after('manual_deduction')
                ->comment("incentive deductions the period's incentive did not cover: taken from the salary");
        });
    }

    public function down(): void
    {
        Schema::table('payslips', function (Blueprint $table) {
            $table->dropColumn('incentive_deduction');
        });
    }
};
