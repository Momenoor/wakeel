<?php

namespace App\Console\Commands;

use App\Models\Matter;
use Illuminate\Console\Command;

/**
 * Custom fields that are dropdowns keep the KEY of the option chosen. Matters saved
 * before the options had keys of their own (or whose keys were changed since) may hold
 * the option's label instead: this puts the key in its place, so the dropdown, the
 * placeholders and the reports all read the same value.
 */
class NormalizeCustomSelectValues extends Command
{
    protected $signature = 'matters:normalize-custom-selects {--dry-run : Only say what would change}';

    protected $description = "Replace a dropdown custom field's saved label with the key of its option";

    public function handle(): int
    {
        $changed = 0;
        $dry = (bool) $this->option('dry-run');

        Matter::query()
            ->whereNotNull('custom_fields')
            ->with('type.fieldDefinitions')
            ->chunkById(200, function ($matters) use (&$changed, $dry): void {
                foreach ($matters as $matter) {
                    $definitions = $matter->type?->fieldDefinitions?->where('type', 'select_input')->keyBy('label') ?? collect();
                    $custom = (array) $matter->custom_fields;
                    $fixed = false;

                    foreach ($custom as $label => $value) {
                        $key = $definitions->get($label)?->optionKey($value);

                        if ($key !== null && $key !== $value) {
                            $this->line(($dry ? 'would change ' : 'changed ').$matter->year.'/'.$matter->number.' — '.$label.': '.$value.' → '.$key);
                            $custom[$label] = $key;
                            $fixed = true;
                            $changed++;
                        }
                    }

                    if ($fixed && ! $dry) {
                        $matter->custom_fields = $custom;
                        $matter->saveQuietly();
                    }
                }
            });

        $this->info(($dry ? 'To change: ' : 'Changed: ').$changed);

        return self::SUCCESS;
    }
}
