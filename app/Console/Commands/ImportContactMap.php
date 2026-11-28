<?php

namespace App\Console\Commands;

use App\Services\MMS\ContactMap;
use Illuminate\Console\Command;

/** The contact map made external entities and their contacts (ContactMap): what is there already stays as it is. */
class ImportContactMap extends Command
{
    protected $signature = 'parties:import-contact-map {path : The contact map, a JSON file of its cards}';

    protected $description = 'Import the shared contact map (courts, banks, government bodies, companies) as external entities and their contacts';

    public function handle(ContactMap $map): int
    {
        $made = $map->importFile((string) $this->argument('path'));

        $this->info("{$made['entities']} new external entities, {$made['contacts']} new contacts.");

        return self::SUCCESS;
    }
}
