<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A letter's language — for a letter written freely, without a template
 * to take it from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matter_letters', function (Blueprint $table) {
            $table->string('locale', 5)->nullable()->after('attention');
        });
    }

    public function down(): void
    {
        Schema::table('matter_letters', function (Blueprint $table) {
            $table->dropColumn('locale');
        });
    }
};
