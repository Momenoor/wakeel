<?php

use App\Services\RichTextConversion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The rich text editors are TinyMCE now: the templates', letters', minutes',
 * emails' and signatures' text written in the editor used before is turned
 * into TinyMCE's (App\Services\RichTextConversion) — each original kept in
 * rich_text_backups first. `php artisan richtext:convert --restore` puts them
 * back; rolling back does the same.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable(RichTextConversion::BACKUPS)) {
            Schema::create(RichTextConversion::BACKUPS, function (Blueprint $table) {
                $table->id();
                $table->string('table_name', 64);
                $table->string('column_name', 64);
                $table->unsignedBigInteger('record_id');
                $table->longText('html')->comment('the text as the editor used before kept it');
                $table->timestamp('converted_at')->nullable();

                $table->unique(['table_name', 'column_name', 'record_id'], 'rich_text_backups_record_unique');
            });
        }

        app(RichTextConversion::class)->convert();
    }

    public function down(): void
    {
        app(RichTextConversion::class)->restore();

        Schema::dropIfExists(RichTextConversion::BACKUPS);
    }
};
