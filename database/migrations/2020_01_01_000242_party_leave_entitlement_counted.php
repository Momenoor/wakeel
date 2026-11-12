<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a vacation added by hand counted against an employee's service-year entitlement (annual taken days, or one of
 * the sick-leave bands): the entitlement row, the counter and the days. Kept so changing or deleting the vacation
 * gives back exactly what it took, even when its dates now fall in another service year.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('party_leaves', function (Blueprint $table) {
            $table->foreignId('entitlement_id')->nullable()->after('pay_factor')
                ->constrained('leave_entitlements')->nullOnDelete();
            $table->string('entitlement_column', 24)->nullable()->after('entitlement_id');
            $table->decimal('entitlement_days', 6, 1)->nullable()->after('entitlement_column');
        });
    }

    public function down(): void
    {
        Schema::table('party_leaves', function (Blueprint $table) {
            $table->dropConstrainedForeignId('entitlement_id');
            $table->dropColumn(['entitlement_column', 'entitlement_days']);
        });
    }
};
