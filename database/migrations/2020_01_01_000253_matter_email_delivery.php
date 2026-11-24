<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What became of a sent email at each address, from Microsoft 365's message trace (MessageTrace): delivered,
 * pending, failed, quarantined, taken for spam — each address's own, and the worst of them for the list.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matter_emails', function (Blueprint $table) {
            $table->string('delivery_status', 20)->nullable()->after('user_id')
                ->comment('the worst of the addresses: delivered, pending, failed, quarantined, spam, recalled, none (no trace)');
            $table->json('delivery')->nullable()->after('delivery_status')
                ->comment('address => {status, at, detail}');
            $table->dateTime('delivery_checked_at')->nullable()->after('delivery');
        });
    }

    public function down(): void
    {
        Schema::table('matter_emails', function (Blueprint $table) {
            $table->dropColumn(['delivery_status', 'delivery', 'delivery_checked_at']);
        });
    }
};
