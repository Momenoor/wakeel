<?php

use App\Support\ScreenPermissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * PDF tools → Merge PDF and Compress PDF, beside Split PDF: each with its own permission, given to whoever may
 * already split PDFs.
 */
return new class extends Migration
{
    public function up(): void
    {
        ScreenPermissions::grant(ScreenPermissions::MERGE_PDF);
        ScreenPermissions::grant(ScreenPermissions::COMPRESS_PDF);
    }

    public function down(): void
    {
        DB::table('permissions')->whereIn('name', [ScreenPermissions::MERGE_PDF, ScreenPermissions::COMPRESS_PDF])->delete();
    }
};
