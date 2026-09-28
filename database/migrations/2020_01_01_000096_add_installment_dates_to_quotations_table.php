<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Due dates the office set by hand on a quotation's expected instalments,
 * in row order — rent rows, then VAT, then the security deposit. Empty
 * means the dates are worked out from the start date.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->json('installment_dates')->nullable()->after('payment_method');
        });
    }

    public function down(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->dropColumn('installment_dates');
        });
    }
};
