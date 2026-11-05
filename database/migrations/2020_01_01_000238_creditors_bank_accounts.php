<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A matter's creditor's bank account: its IBAN and bank — to pay its share of the distribution into.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matter_creditors', function (Blueprint $table) {
            $table->string('iban', 34)->nullable()->after('id_number')->comment('Its IBAN, without spaces');
            $table->string('bank_name')->nullable()->after('iban');
        });
    }

    public function down(): void
    {
        Schema::table('matter_creditors', function (Blueprint $table) {
            $table->dropColumn(['iban', 'bank_name']);
        });
    }
};
