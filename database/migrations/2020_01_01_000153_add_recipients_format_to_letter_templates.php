<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The words of a template's formal recipients layout: its lines, and what
 * joins the emails and the phones.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('letter_templates', function (Blueprint $table) {
            $table->json('recipients_format')->nullable()->comment('{lines, email_separator, phone_separator}; empty: the standard formal lines');
        });
    }

    public function down(): void
    {
        Schema::table('letter_templates', function (Blueprint $table) {
            $table->dropColumn('recipients_format');
        });
    }
};
