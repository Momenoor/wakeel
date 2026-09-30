<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An employee's yearly flight ticket, and whether it has been paid — through
 * a payroll run (payroll_run_id is set when it is added to one, payslip_id
 * when that run's payslips carry it) or directly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_profiles', function (Blueprint $table) {
            $table->boolean('flight_ticket_entitled')->default(false)->after('opening_leave_balance');
            $table->decimal('flight_ticket_amount', 12, 2)->default(0)->after('flight_ticket_entitled');
        });

        Schema::create('flight_tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('party_id')->constrained('parties')->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->decimal('amount', 12, 2);
            $table->boolean('is_prorated')->default(false);
            $table->foreignId('payroll_run_id')->nullable()->constrained('payroll_runs')->nullOnDelete();
            $table->foreignId('payslip_id')->nullable()->constrained('payslips')->nullOnDelete();
            $table->date('paid_at')->nullable();
            $table->string('paid_via', 20)->nullable();
            $table->string('note')->nullable();
            $table->timestamps();

            $table->unique(['party_id', 'year']);
        });

        Schema::table('payslips', function (Blueprint $table) {
            $table->decimal('flight_ticket_amount', 12, 2)->default(0)->after('incentive_overridden');
        });
    }

    public function down(): void
    {
        Schema::table('payslips', fn (Blueprint $table) => $table->dropColumn('flight_ticket_amount'));
        Schema::dropIfExists('flight_tickets');
        Schema::table('employee_profiles', fn (Blueprint $table) => $table->dropColumn(['flight_ticket_entitled', 'flight_ticket_amount']));
    }
};
