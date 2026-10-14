<?php

use App\Services\MMS\Letters\RaggedTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Letter and minutes templates imported from Word kept tables whose rows are
 * not the same width, and the rich editor padded them with columns and rows of
 * its own as soon as a cell was edited. Evened out here; a template without
 * such a table is not touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('letter_templates')->select(['id', 'body'])->orderBy('id')->each(function (object $template): void {
            $body = (string) $template->body;
            $evened = RaggedTables::even($body);

            if ($evened !== $body) {
                DB::table('letter_templates')->where('id', $template->id)->update(['body' => $evened]);
            }
        });
    }

    public function down(): void
    {
        // The rows' original widths were never worth keeping.
    }
};
