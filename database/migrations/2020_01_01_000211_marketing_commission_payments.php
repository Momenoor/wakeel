<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A marketing commission earned by a payment keeps it (allocation_id): the payment's date changed, the commission is
 * dated with it while not yet paid (MarketingCommissions::redate()). And an assistant's line says whether the
 * points added to it (marketed_rate) were because they marketed the matter themself (marketed_by_self).
 * Plain added columns: nothing of before changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketing_commissions', function (Blueprint $table) {
            $table->foreignId('allocation_id')->nullable()->after('party_id')->constrained()->nullOnDelete()->comment('the payment it was earned by');
        });

        Schema::table('incentive_assistant_lines', function (Blueprint $table) {
            $table->boolean('marketed_by_self')->default(false)->after('marketed_rate')->comment('marketed_rate is the points added because they marketed the matter themself');
        });
    }

    public function down(): void
    {
        Schema::table('incentive_assistant_lines', function (Blueprint $table) {
            $table->dropColumn('marketed_by_self');
        });
        Schema::table('marketing_commissions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('allocation_id');
        });
    }
};
