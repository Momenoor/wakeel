<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An employee's name in Arabic, beside the one in English (the WPS form's): a letter (an employee's
 * certificate) names them in its own language.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_profiles', function (Blueprint $table) {
            $table->string('name_ar')->nullable()->after('display_name')->comment('The name in Arabic, for Arabic letters');
        });
    }

    public function down(): void
    {
        Schema::table('employee_profiles', function (Blueprint $table) {
            $table->dropColumn('name_ar');
        });
    }
};
