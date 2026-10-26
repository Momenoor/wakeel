<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who brought a matter in, and what they earn for it (App\Services\MMS\MarketingCommissions).
 *
 * A matter's marketers are its parties of the role "marketer" — "staff" (an employee of the office) or "external"
 * — each with their part of the matter's marketing share (none: equal parts). The share is the matter's
 * marketing_rate (a percentage of its fees' basic amount) or its marketing_fixed amount. They earn it as the
 * client pays: each a commission row, kept as what changes (a payment, a credit note, a marketer added or taken
 * off) — a staff marketer's in the incentive calculation of its period (a line of its own: is_marketing), an
 * external marketer's owed until marked paid. An assistant on a matter someone else marketed is paid a flat
 * rate instead (marketed_rate, on their line). New columns with defaults and a new table: nothing of before
 * changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matters', function (Blueprint $table) {
            $table->decimal('marketing_rate', 5, 2)->nullable()->comment('the marketing share: a percentage of its fees\' basic amount');
            $table->decimal('marketing_fixed', 15, 2)->nullable()->comment('the marketing share as a fixed amount, instead of a percentage');
        });

        Schema::table('matter_party', function (Blueprint $table) {
            $table->decimal('marketing_part', 5, 2)->nullable()->comment('a marketer\'s part of the matter\'s marketing share, in percent; none: equal parts');
        });

        Schema::create('marketing_commissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('matter_id')->constrained()->cascadeOnDelete();
            $table->foreignId('party_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 10)->comment('staff (paid in the incentive calculation) or external (owed until marked paid)');
            $table->date('date');
            $table->decimal('base', 15, 2)->default(0)->comment('the fees\' basic amount collected, counted then');
            $table->decimal('amount', 15, 2)->comment('earned by this change; below zero, taken back');
            $table->text('description')->nullable();
            $table->foreignId('incentive_calculation_id')->nullable()->constrained()->nullOnDelete()->comment('staff: the calculation it is paid in');
            $table->date('paid_at')->nullable()->comment('external: when it was paid');
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['matter_id', 'party_id']);
            $table->index(['kind', 'paid_at']);
        });

        Schema::table('incentive_lines', function (Blueprint $table) {
            $table->boolean('is_marketing')->default(false)->comment('a staff marketer\'s commission, not a fee');
        });

        Schema::table('incentive_assistant_lines', function (Blueprint $table) {
            $table->foreignId('marketing_commission_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('marketed_rate', 5, 2)->nullable()->comment('the flat rate it was paid at, someone else having marketed the matter');
        });
    }

    public function down(): void
    {
        Schema::table('incentive_assistant_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('marketing_commission_id');
            $table->dropColumn('marketed_rate');
        });
        Schema::table('incentive_lines', function (Blueprint $table) {
            $table->dropColumn('is_marketing');
        });
        Schema::dropIfExists('marketing_commissions');
        Schema::table('matter_party', function (Blueprint $table) {
            $table->dropColumn('marketing_part');
        });
        Schema::table('matters', function (Blueprint $table) {
            $table->dropColumn(['marketing_rate', 'marketing_fixed']);
        });
    }
};
