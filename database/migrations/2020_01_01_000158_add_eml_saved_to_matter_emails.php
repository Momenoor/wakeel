<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether the original of an email that came in (.eml) was saved in the
 * matter's OneDrive folder — it is kept nowhere else.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matter_emails', function (Blueprint $table) {
            $table->boolean('eml_saved')->default(false)->comment('The original (.eml) was saved in the matter\'s OneDrive');
        });
    }

    public function down(): void
    {
        Schema::table('matter_emails', function (Blueprint $table) {
            $table->dropColumn('eml_saved');
        });
    }
};
