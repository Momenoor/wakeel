<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A fee deleted after its incentive was paid no longer changes the paid calculation: its line stays there (its
 * fee_id emptied, no longer deleted with the fee), and each assistant's share of it is taken back in the next
 * calculation — a negative line of their own, saying what it is (IncentiveReversals).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incentive_lines', function (Blueprint $table) {
            $table->dropForeign(['fee_id']);
            $table->foreign('fee_id')->references('id')->on('fees')->nullOnDelete();
            $table->boolean('is_reversal')->default(false)->after('fee_id')
                ->comment('a paid incentive taken back: a fee deleted after its calculation was finalized');
        });

        Schema::create('incentive_reversals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('matter_id')->nullable()->constrained('matters')->nullOnDelete();
            $table->foreignId('party_id')->constrained('parties')->cascadeOnDelete()->comment('the assistant it is taken from');
            // The fee as it was — the fee itself is gone.
            $table->unsignedBigInteger('fee_id')->nullable();
            $table->string('fee_type')->nullable();
            $table->decimal('fee_amount', 12, 2)->default(0);
            $table->date('fee_date')->nullable();
            $table->foreignId('paid_line_id')->nullable()->constrained('incentive_lines')->nullOnDelete();
            $table->foreignId('paid_calculation_id')->nullable()->constrained('incentive_calculations')->nullOnDelete();
            $table->decimal('paid_amount', 12, 2)->default(0)->comment("the assistant's total for the matter in that calculation");
            $table->decimal('amount', 12, 2)->comment("the deleted fee's part of it: taken back");
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('applied_calculation_id')->nullable()->constrained('incentive_calculations')->nullOnDelete()
                ->comment('the calculation it is taken back in; empty until one is run (or when that draft is deleted)');
            $table->timestamps();
        });

        Schema::table('incentive_assistant_lines', function (Blueprint $table) {
            $table->foreignId('incentive_reversal_id')->nullable()->after('party_id')->constrained('incentive_reversals')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('incentive_assistant_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('incentive_reversal_id');
        });
        Schema::dropIfExists('incentive_reversals');
        Schema::table('incentive_lines', function (Blueprint $table) {
            $table->dropColumn('is_reversal');
            $table->dropForeign(['fee_id']);
            $table->foreign('fee_id')->references('id')->on('fees')->cascadeOnDelete();
        });
    }
};
