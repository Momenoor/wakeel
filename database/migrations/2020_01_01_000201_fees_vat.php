<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A fee's VAT on the fee itself: `amount` stays its basic (VAT-exclusive) amount, `vat` the VAT on it, so its
 * total is amount + vat; `is_vat_included` whether the amount was entered as that total (App\Support\Vat).
 *
 * Every fee before this keeps exactly what it was: vat 0, not included — its total its amount. The separate VAT
 * rows of before are merged into their fees only when asked (Fee Data Maintenance, or php artisan fees:fix-vat), never here.
 * Two plain added columns with defaults: an instant change on MySQL, nothing for the queue.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fees', function (Blueprint $table) {
            $table->decimal('vat', 15, 2)->default(0)->after('amount')->comment('the VAT on the fee; total = amount + vat');
            $table->boolean('is_vat_included')->default(false)->after('vat')->comment('the amount was entered as the total, VAT included');
        });
    }

    public function down(): void
    {
        Schema::table('fees', function (Blueprint $table) {
            $table->dropColumn(['vat', 'is_vat_included']);
        });
    }
};
