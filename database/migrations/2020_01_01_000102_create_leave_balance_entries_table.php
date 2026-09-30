<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The annual-leave balance as a running record: every day added (opening
 * balance, the 1 January grant, a new joiner's pro-rated grant, an HR
 * adjustment) and every day taken, each on its own row. The balance is the
 * sum, so any figure can be traced back to what made it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_balance_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('party_id')->constrained('parties')->cascadeOnDelete();
            $table->string('kind', 20);
            $table->decimal('days', 6, 1)->comment('Positive adds to the balance, negative takes from it');
            $table->date('entry_date');
            $table->unsignedSmallInteger('year')->nullable()->comment('The year a grant is for — one of each kind per year');
            $table->foreignId('leave_request_period_id')->nullable()->unique()
                ->constrained('leave_request_periods')->cascadeOnDelete();
            $table->string('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['party_id', 'kind', 'year'], 'lbe_party_kind_year_unique');
            $table->index(['party_id', 'entry_date']);
        });

        // Tracking starts today (the date on these first entries marks it —
        // LeaveBalanceService::startedOn()). Each employee begins from the
        // opening balance on their profile; HR corrects anyone who is off
        // with an adjustment.
        $today = now()->toDateString();

        DB::table('employee_profiles')
            ->whereNotNull('party_id')
            ->select(['party_id', 'opening_leave_balance'])
            ->orderBy('id')
            ->each(function (object $profile) use ($today): void {
                DB::table('leave_balance_entries')->insert([
                    'party_id' => $profile->party_id,
                    'kind' => 'opening',
                    'days' => (float) ($profile->opening_leave_balance ?? 0),
                    'entry_date' => $today,
                    'year' => null,
                    'note' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_balance_entries');
    }
};
