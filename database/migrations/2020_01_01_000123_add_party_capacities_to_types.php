<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What each side of a matter is called in matters of a type — المتنازع /
 * المتنازع ضدها, الطاعن / المطعون عليه … — instead of المدعي / المدعى عليه.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('types', function (Blueprint $table) {
            $table->json('party_capacities')->nullable()->comment('{plaintiff, defendant, implicate-litigant} => Arabic name');
        });
    }

    public function down(): void
    {
        Schema::table('types', function (Blueprint $table) {
            $table->dropColumn('party_capacities');
        });
    }
};
