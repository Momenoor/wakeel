<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An employee's flight ticket asked for in advance (Employee requests): the year it is, and — once paid — the
 * year's ticket it paid (FlightTicket, marked paid directly: payroll does not pay it again).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_requests', function (Blueprint $table) {
            $table->unsignedSmallInteger('ticket_year')->nullable()->after('starts_on')->comment('A ticket advance: the year of the ticket');
            $table->foreignId('flight_ticket_id')->nullable()->after('employee_loan_id')->constrained('flight_tickets')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('employee_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('flight_ticket_id');
            $table->dropColumn('ticket_year');
        });
    }
};
