<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How tall the signature and stamp are drawn in a letter, in mm — the
 * width follows the image. The sizes letters used until now.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('letterheads', function (Blueprint $table) {
            $table->decimal('signature_height', 6, 2)->default(45)->after('signature_image');
            $table->decimal('stamp_height', 6, 2)->default(40)->after('stamp_image');
        });
    }

    public function down(): void
    {
        Schema::table('letterheads', function (Blueprint $table) {
            $table->dropColumn(['signature_height', 'stamp_height']);
        });
    }
};
