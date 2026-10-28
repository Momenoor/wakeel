<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Unpaid leave lowers the salaries expense in the journal voucher, not an "Unpaid Leave Recovery" account the
 * office does not have: the payslips' unpaid leave lines already written moved to it too, so the vouchers of the
 * runs before say the same.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('payslip_lines')->where('gl_account', 'Unpaid Leave Recovery')->update(['gl_account' => 'Salaries Expense']);
    }

    public function down(): void
    {
        DB::table('payslip_lines')->where('gl_account', 'Salaries Expense')->where('kind', 'deduction')->update(['gl_account' => 'Unpaid Leave Recovery']);
    }
};
