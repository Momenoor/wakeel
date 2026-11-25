<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a calculation pays its staff marketers' commissions (MarketingCommissions::apply), or keeps them pending
 * for an upcoming calculation. On by default: as every calculation did before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incentive_calculations', function (Blueprint $table) {
            $table->boolean('apply_marketing_commissions')->default(true)->after('apply_previous_adjustments')
                ->comment("pay the staff marketers' commissions here (MarketingCommissions), or keep them pending for an upcoming calculation");
        });
    }

    public function down(): void
    {
        Schema::table('incentive_calculations', function (Blueprint $table) {
            $table->dropColumn('apply_marketing_commissions');
        });
    }
};
