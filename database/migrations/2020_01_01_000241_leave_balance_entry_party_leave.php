<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A vacation added by hand takes its days off the annual balance like an approved request does, so its balance
 * entry needs a link back to it (leave_request_period_id is the request's). One entry per vacation; deleting the
 * vacation deletes the entry.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_balance_entries', function (Blueprint $table) {
            $table->foreignId('party_leave_id')->nullable()->unique()->after('leave_request_period_id')
                ->constrained('party_leaves')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('leave_balance_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('party_leave_id');
        });
    }
};
