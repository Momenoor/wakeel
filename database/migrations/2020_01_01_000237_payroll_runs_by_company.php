<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A payroll run for each of the office's companies: its own employees (those it sponsors) and its own details on
 * the Salary Authorization Form and the journal voucher — its WPS employer ID, its GL number at the exchange house,
 * the exchange house itself and its transfer fee. One run a month for each company now, not one for the office; a
 * run with no company pays the employees with none (until now: everyone).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('wps_employer_id')->nullable()->after('establishment_card_expiry')->comment('Its employer ID on the WPS (MOHRE)');
            $table->string('wps_gl_number')->nullable()->after('wps_employer_id')->comment('Its own account number at the exchange house');
            $table->string('wps_exchange_house')->nullable()->after('wps_gl_number')->comment('Empty: the one the form names by default');
            $table->decimal('payroll_bank_fee_amount', 12, 2)->nullable()->after('wps_exchange_house')->comment('Empty: Financial Settings → Payroll');
        });

        Schema::table('payroll_runs', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->after('period')->constrained('companies')->nullOnDelete();
            $table->dropUnique(['period']);
            $table->unique(['period', 'company_id']);
        });
    }

    public function down(): void
    {
        Schema::table('payroll_runs', function (Blueprint $table) {
            $table->dropUnique(['period', 'company_id']);
            $table->dropConstrainedForeignId('company_id');
            $table->unique('period');
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['wps_employer_id', 'wps_gl_number', 'wps_exchange_house', 'payroll_bank_fee_amount']);
        });
    }
};
