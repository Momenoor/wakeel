<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Top and bottom margins for the pages after the first — often smaller,
 * with no letterhead header or footer to clear. Empty means the first
 * page's. (Left and right are the same on every page: the PDF engine
 * cannot vary them per page.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('letterheads', function (Blueprint $table) {
            $table->decimal('other_margin_top', 6, 2)->nullable()->after('margin_left');
            $table->decimal('other_margin_bottom', 6, 2)->nullable()->after('other_margin_top');
        });
    }

    public function down(): void
    {
        Schema::table('letterheads', function (Blueprint $table) {
            $table->dropColumn(['other_margin_top', 'other_margin_bottom']);
        });
    }
};
