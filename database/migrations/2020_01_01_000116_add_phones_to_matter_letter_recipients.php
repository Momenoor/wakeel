<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A letter's recipient's phone numbers, printed under their name with
 * their emails.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matter_letter_recipients', function (Blueprint $table) {
            $table->json('phones')->nullable()->after('emails');
        });
    }

    public function down(): void
    {
        Schema::table('matter_letter_recipients', function (Blueprint $table) {
            $table->dropColumn('phones');
        });
    }
};
