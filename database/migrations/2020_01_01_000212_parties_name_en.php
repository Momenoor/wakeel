<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A party's name in English, beside the one they are known by (mostly Arabic): English letters name them by
 * it — translated by Claude (App\Services\Translation\NameTranslator) when one is set, else typed in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parties', function (Blueprint $table) {
            $table->string('name_en')->nullable()->after('legal_name')->comment('The name in English, for English letters');
        });
    }

    public function down(): void
    {
        Schema::table('parties', function (Blueprint $table) {
            $table->dropColumn('name_en');
        });
    }
};
