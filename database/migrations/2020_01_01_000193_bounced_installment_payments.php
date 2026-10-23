<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A payment whose cheque bounced stays in the instalment's ledger, marked: it no longer counts towards what
 * was paid. Before, the ledger kept counting it, so the next payment after a bounce added the bounced amount
 * back (4,000 bounced, then 6,100 paid, showed a 10,000 instalment as fully paid).
 *
 * Payments of an instalment bounced now are those its bounce reverted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('installment_payments', function (Blueprint $table) {
            $table->timestamp('bounced_at')->nullable()->after('paid_date');
        });

        DB::table('installment_payments')
            ->whereIn('installment_id', DB::table('installments')->where('payment_status', 'bounced')->select('id'))
            ->update(['bounced_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('installment_payments', function (Blueprint $table) {
            $table->dropColumn('bounced_at');
        });
    }
};
