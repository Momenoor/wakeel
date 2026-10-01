<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A letter's recipient goes with their legal representatives: who they are
 * (name, emails), and whether the letter names them on their own lines
 * ("ووكيله السادة/ …") or just says "ووكيله القانوني".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matter_letter_recipients', function (Blueprint $table) {
            $table->json('representatives')->nullable()->after('emails');
            $table->boolean('name_representatives')->default(false)->after('representatives');
        });
    }

    public function down(): void
    {
        Schema::table('matter_letter_recipients', function (Blueprint $table) {
            $table->dropColumn(['representatives', 'name_representatives']);
        });
    }
};
