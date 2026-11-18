<?php

use App\Filament\Mms\Imports\BulkMailRecipientImporter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Recipients imported with their addresses in one cell, split by ";" (or "؛", "،", a new line): the importer
 * took the cell for one address, kept as one value that no mail could go to. Each address made a value of its
 * own (BulkMailRecipientImporter::addresses()).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('bulk_mail_recipients')
            // Every row read: an Arabic separator is kept escaped in the JSON ("؛"), not found by LIKE.
            ->select(['id', 'email'])
            ->orderBy('id')
            ->chunkById(500, function ($rows): void {
                foreach ($rows as $row) {
                    $before = (array) (json_decode((string) $row->email, true) ?? []);
                    $after = BulkMailRecipientImporter::addresses($before);

                    if ($after !== $before) {
                        DB::table('bulk_mail_recipients')->where('id', $row->id)->update(['email' => json_encode($after, JSON_UNESCAPED_UNICODE)]);
                    }
                }
            });
    }

    public function down(): void
    {
        // The addresses stay apart: joining them again would only break them.
    }
};
