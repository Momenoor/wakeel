<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A fixed amount added to an assistant's incentive in a calculation, or taken off it, entered by hand with its
 * reason: a line of its own in the assistant's calculation, of no matter (IncentiveAdjustments).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incentive_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incentive_calculation_id')->constrained('incentive_calculations')->cascadeOnDelete();
            $table->foreignId('party_id')->constrained('parties')->cascadeOnDelete()->comment('the assistant');
            $table->string('type')->comment('addition or deduction (IncentiveAdjustmentType)');
            $table->decimal('amount', 12, 2);
            $table->string('reason');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('incentive_lines', function (Blueprint $table) {
            $table->foreignId('matter_id')->nullable()->change();
            $table->boolean('is_adjustment')->default(false)->after('is_reversal')
                ->comment('an amount added or taken off by hand: no matter, no fee');
        });

        Schema::table('incentive_assistant_lines', function (Blueprint $table) {
            $table->foreignId('incentive_adjustment_id')->nullable()->after('incentive_reversal_id')
                ->constrained('incentive_adjustments')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        DB::table('incentive_lines')->where('is_adjustment', true)->delete();

        Schema::table('incentive_assistant_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('incentive_adjustment_id');
        });
        Schema::table('incentive_lines', function (Blueprint $table) {
            $table->dropColumn('is_adjustment');
            $table->foreignId('matter_id')->nullable(false)->change();
        });
        Schema::dropIfExists('incentive_adjustments');
    }
};
