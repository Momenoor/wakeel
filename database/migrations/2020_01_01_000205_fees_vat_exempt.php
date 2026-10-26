<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A fee VAT does not apply to (App\Filament\Support\FeeVatFields): kept with no VAT whatever its type — its total
 * its amount — and said so. Every fee before this keeps what it was. A plain added column with a default: an
 * instant change on MySQL, nothing for the queue.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fees', function (Blueprint $table) {
            $table->boolean('is_vat_exempt')->default(false)->after('is_vat_included')->comment('VAT does not apply to this fee: its vat 0, its total its amount');
        });
    }

    public function down(): void
    {
        Schema::table('fees', function (Blueprint $table) {
            $table->dropColumn('is_vat_exempt');
        });
    }
};
