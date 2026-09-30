<?php

use App\Services\MMS\SentMailImporter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Recipients brought in from sent mail before the importer read the
 * addressee from the letter ("السادة/ Arco Interiors LLC …"): named now
 * from their own saved body, where it has that line.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('bulk_mail_recipients')
            ->whereNotNull('sent_body')
            ->select(['id', 'sent_body'])
            ->orderBy('id')
            ->each(function (object $recipient): void {
                $name = SentMailImporter::addressee((string) $recipient->sent_body);

                if ($name !== null) {
                    DB::table('bulk_mail_recipients')->where('id', $recipient->id)->update(['name' => $name]);
                }
            });
    }

    public function down(): void
    {
        //
    }
};
