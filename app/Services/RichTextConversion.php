<?php

namespace App\Services;

use App\Services\MMS\BulkMailService;
use App\Support\EditorHtml;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every rich text kept from the editor used before (Filament's, Tiptap) turned
 * into TinyMCE's (EditorHtml::fromTiptap) — the sources letters, minutes and
 * emails are written from, which the editors open:
 *
 *   letter_templates.body      templates (minutes templates too)
 *   matter_letters.body        each issued letter's wording, which its PDF and Word are made from again
 *   matter_minutes.body        finalised minutes' wording, likewise
 *   email_templates.body       covering emails, minutes for signature
 *   bulk_mail_campaigns.body   bulk mail
 *   mail_senders.signature     the mailboxes' signatures
 *
 * Not matter_letters.rendered_html: the letter as issued, already filled in
 * and laid out — left as it went out. The conversion does nothing to what a
 * letter says or how it looks (placeholders, alignment and widths become
 * what the output already made of them), and touches only text written by
 * the old editor, so it can run again at any time.
 *
 * Each text's original is kept in rich_text_backups (once — the first
 * original, however often this runs) and can be put back with restore().
 */
class RichTextConversion
{
    public const BACKUPS = 'rich_text_backups';

    /**
     * @return array<string, array{table: string, column: string}>
     */
    public static function columns(): array
    {
        return [
            'letter_templates.body' => ['table' => 'letter_templates', 'column' => 'body'],
            'matter_letters.body' => ['table' => 'matter_letters', 'column' => 'body'],
            'matter_minutes.body' => ['table' => 'matter_minutes', 'column' => 'body'],
            'email_templates.body' => ['table' => 'email_templates', 'column' => 'body'],
            'bulk_mail_campaigns.body' => ['table' => 'bulk_mail_campaigns', 'column' => 'body'],
            'mail_senders.signature' => ['table' => 'mail_senders', 'column' => 'signature'],
        ];
    }

    /**
     * Converts (or, dry, only counts) every text still in the old editor's
     * HTML.
     *
     * @param  (callable(string, int|string, string, string): void)|null  $each  told of each change: column, id, before, after
     * @return array<string, array{checked: int, converted: int}>
     */
    public function convert(bool $dryRun = false, ?callable $each = null): array
    {
        $report = [];

        foreach (self::columns() as $name => ['table' => $table, 'column' => $column]) {
            $report[$name] = ['checked' => 0, 'converted' => 0];

            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }

            DB::table($table)->select(['id', $column])->whereNotNull($column)->orderBy('id')
                ->chunkById(200, function ($rows) use ($table, $column, $name, $dryRun, $each, &$report): void {
                    foreach ($rows as $row) {
                        $report[$name]['checked']++;
                        $before = (string) $row->{$column};

                        if (! EditorHtml::isTiptap($before)) {
                            continue;
                        }

                        $after = (string) EditorHtml::fromTiptap($before, $this->rightToLeft($table, $row->id, $before));

                        if ($after === $before) {
                            continue;
                        }

                        $report[$name]['converted']++;
                        if ($each) {
                            $each($name, $row->id, $before, $after);
                        }

                        if ($dryRun) {
                            continue;
                        }

                        DB::transaction(function () use ($table, $column, $row, $before, $after): void {
                            // The first original only: a text converted, edited
                            // and somehow converted again keeps its first backup.
                            DB::table(self::BACKUPS)->insertOrIgnore([
                                'table_name' => $table,
                                'column_name' => $column,
                                'record_id' => $row->id,
                                'html' => $before,
                                'converted_at' => now(),
                            ]);

                            DB::table($table)->where('id', $row->id)->update([$column => $after]);
                        });
                    }
                }, 'id');
        }

        return $report;
    }

    /**
     * Every converted text back as it was before, from its backup (the backups
     * are kept). What was written in the new editor since is lost with it.
     *
     * @return int how many were put back
     */
    public function restore(): int
    {
        if (! Schema::hasTable(self::BACKUPS)) {
            return 0;
        }

        $restored = 0;

        DB::table(self::BACKUPS)->orderBy('id')->chunkById(200, function ($backups) use (&$restored): void {
            foreach ($backups as $backup) {
                if (! Schema::hasTable($backup->table_name) || ! array_key_exists($backup->table_name.'.'.$backup->column_name, self::columns())) {
                    continue;
                }

                $restored += DB::table($backup->table_name)->where('id', $backup->record_id)->update([$backup->column_name => $backup->html]);
            }
        });

        return $restored;
    }

    /**
     * Whether a text is right to left — its start the right — as its editor
     * showed it: by its language, or for bulk mail by what it says. A
     * signature was written left to right.
     */
    private function rightToLeft(string $table, int|string $id, string $html): bool
    {
        return match ($table) {
            'letter_templates', 'email_templates' => DB::table($table)->where('id', $id)->value('locale') !== 'en',
            'matter_letters' => (DB::table('matter_letters')->where('id', $id)->value('locale') ?? $this->templateLocale('matter_letters', $id)) !== 'en',
            'matter_minutes' => $this->templateLocale('matter_minutes', $id) !== 'en',
            'bulk_mail_campaigns' => BulkMailService::containsArabic($html),
            default => false,
        };
    }

    private function templateLocale(string $table, int|string $id): ?string
    {
        $template = DB::table($table)->where('id', $id)->value('letter_template_id');

        return $template ? DB::table('letter_templates')->where('id', $template)->value('locale') : null;
    }
}
