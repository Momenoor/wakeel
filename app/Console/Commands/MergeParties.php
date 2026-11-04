<?php

namespace App\Console\Commands;

use App\Models\Party;
use App\Services\MMS\PartyMerger;
use Illuminate\Console\Command;
use Throwable;

/**
 * Parties that are one, merged from the command line: `php artisan parties:merge 12 34 56` keeps party 12 and
 * merges 34 and 56 into it (PartyMerger) — everything of theirs moved to it, their phones and emails added to
 * its own, then deleted. Asks first, unless run with --no-interaction.
 */
class MergeParties extends Command
{
    protected $signature = 'parties:merge {into : The id of the party kept} {from* : The ids of the parties merged into it}';

    protected $description = 'Merge two or more parties into one: their matters, letters, emails, contacts and the rest moved to the one kept, phones and emails combined, the others deleted';

    public function handle(PartyMerger $merger): int
    {
        $into = Party::query()->find((int) $this->argument('into'));
        $from = Party::query()->findMany(array_map('intval', (array) $this->argument('from')))->reject(fn (Party $party): bool => $into !== null && $party->is($into));

        if ($into === null) {
            $this->error('No party has the id '.$this->argument('into').'.');

            return self::FAILURE;
        }

        if ($from->isEmpty()) {
            $this->error('Name the ids of the parties to merge into it, other than its own.');

            return self::FAILURE;
        }

        $this->line('Kept: #'.$into->getKey().' '.$into->name);
        foreach ($from as $party) {
            $this->line('Merged into it and deleted: #'.$party->getKey().' '.$party->name);
        }

        if (! $this->confirm('Go ahead? It cannot be undone.', true)) {
            return self::SUCCESS;
        }

        try {
            $result = $merger->merge($into, $from);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        foreach ($result['moved'] as $what => $count) {
            $this->line(sprintf('%6d  %s', $count, $what));
        }
        $this->info($result['removed'].' merged into #'.$into->getKey().' '.$into->name.' — phones: '.implode(', ', Party::values($into->phone)).'; emails: '.implode(', ', Party::values($into->email)));

        return self::SUCCESS;
    }
}
