<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A credit note on a fee (App\Models\FeeCreditNote): the fee reduced by it — its basic amount and its VAT — and
 * the note kept with its number, date, reason and document, the fee as it was before it. And a paid incentive
 * changed, not only taken back whole (IncentiveReversals): why — its fee deleted, corrected, or credited — and
 * the fee's basic amount after it; its amount below zero for a fee corrected upward (paid extra).
 * A new table and plain added columns with defaults: nothing of before changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_credit_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('matter_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete()->comment('who entered it');
            $table->string('number')->nullable()->comment('the credit note\'s own number');
            $table->date('date');
            $table->decimal('basic', 15, 2)->comment('what it takes off the fee\'s basic amount');
            $table->decimal('vat', 15, 2)->default(0)->comment('what it takes off the fee\'s VAT');
            $table->decimal('fee_amount_before', 15, 2)->comment('the fee\'s basic amount before it');
            $table->decimal('fee_vat_before', 15, 2)->default(0);
            $table->text('reason')->nullable();
            $table->timestamps();

            $table->index(['matter_id', 'date']);
        });

        Schema::table('incentive_reversals', function (Blueprint $table) {
            $table->string('reason', 20)->default('deleted')->after('fee_date')->comment('deleted, corrected or credit_note');
            $table->decimal('fee_amount_after', 15, 2)->nullable()->after('reason')->comment('corrected or credited: its basic amount after');
            $table->foreignId('credit_note_id')->nullable()->after('fee_amount_after')->constrained('fee_credit_notes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('incentive_reversals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('credit_note_id');
            $table->dropColumn(['reason', 'fee_amount_after']);
        });
        Schema::dropIfExists('fee_credit_notes');
    }
};
