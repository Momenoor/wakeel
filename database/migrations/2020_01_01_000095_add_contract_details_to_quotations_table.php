<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The contract a quotation proposes — its period, grace period, type and
 * how it would be paid — so the expected instalments can be shown on it
 * and it can be turned into a lease without typing them again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->date('start_date')->nullable()->after('validity_date');
            $table->date('end_date')->nullable()->after('start_date');
            $table->unsignedInteger('grace_period_days')->default(0)->after('end_date');
            $table->string('contract_type')->nullable()->after('grace_period_days');
            $table->string('payment_method')->nullable()->after('number_of_installments');
        });
    }

    public function down(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->dropColumn(['start_date', 'end_date', 'grace_period_days', 'contract_type', 'payment_method']);
        });
    }
};
