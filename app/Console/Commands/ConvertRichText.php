<?php

namespace App\Console\Commands;

use App\Services\RichTextConversion;
use Illuminate\Console\Command;

/**
 * Turns text written in the editor used before (Filament's) into TinyMCE's —
 * what the migration did on updating; safe to run again (only old text is
 * touched, each original backed up once). --dry-run says what would change;
 * --restore puts every converted text back as it was.
 */
class ConvertRichText extends Command
{
    protected $signature = 'richtext:convert
        {--dry-run : Only say what would change}
        {--restore : Put every converted text back as it was before}
        {--show : Print each text before and after}
        {--force : With --restore, do not ask first}';

    protected $description = 'Convert rich text kept by the old editor (Tiptap) to TinyMCE\'s, or restore it';

    public function handle(RichTextConversion $conversion): int
    {
        if ($this->option('restore')) {
            if (! $this->option('force') && ! $this->confirm('Put every converted text back as it was before? What was written in the new editor since is lost.')) {
                return self::FAILURE;
            }

            $this->info($conversion->restore().' text(s) restored.');

            return self::SUCCESS;
        }

        $dry = (bool) $this->option('dry-run');
        $show = (bool) $this->option('show');

        $report = $conversion->convert($dry, function (string $column, int|string $id, string $before, string $after) use ($dry, $show): void {
            $this->line(($dry ? 'would convert ' : 'converted ').$column.' #'.$id);

            if ($show) {
                $this->line('  before: '.$before);
                $this->line('  after:  '.$after);
            }
        });

        $this->table(['Column', 'Texts', $dry ? 'Would convert' : 'Converted'], collect($report)->map(fn (array $r, string $column) => [$column, $r['checked'], $r['converted']])->values()->all());

        return self::SUCCESS;
    }
}
