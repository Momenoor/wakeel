<?php

namespace App\Console\Commands;

use App\Models\Attachment;
use App\Models\MinutesDelivery;
use App\Services\MMS\MatterOneDriveFolders;
use Illuminate\Console\Command;

/**
 * The signed copies' links in OneDrive that were cut at 255 characters (the column's length until migration
 * 000248), found again: each looked for in its matter's signed-minutes folder — the only file of its size there,
 * else the one of its name — and its whole link kept. Run once after the update; run again, it finds none.
 */
class RelinkSignedMinutes extends Command
{
    /** What the column took: a link of this length was cut. */
    private const CUT = 255;

    protected $signature = 'minutes:relink-signed-copies';

    protected $description = 'Finds again in OneDrive the signed copies of minutes whose link was cut short';

    public function handle(MatterOneDriveFolders $folders): int
    {
        $cut = MinutesDelivery::query()->whereNotNull('onedrive_url')->with('minutes.matter')->get()
            ->filter(fn (MinutesDelivery $delivery): bool => strlen((string) $delivery->onedrive_url) === self::CUT);

        if ($cut->isEmpty()) {
            $this->info('No link was cut.');

            return self::SUCCESS;
        }

        // Each one's latest copy (the link is the last put there), read at once.
        $copyOf = $cut->mapWithKeys(fn (MinutesDelivery $delivery): array => [$delivery->getKey() => collect($delivery->signed_attachments ?? [])->last()]);
        $attachments = Attachment::query()->whereIn('id', $copyOf->filter()->all())->get(['id', 'name', 'size'])->keyBy('id');

        // Each matter's folder asked of OneDrive once.
        $files = [];
        $found = 0;

        foreach ($cut as $delivery) {
            $matter = $delivery->minutes?->matter;
            $attachment = $attachments->get($copyOf[$delivery->getKey()]);
            $file = null;

            if ($matter && $attachment) {
                try {
                    $files[$matter->getKey()] ??= $folders->signedMinutesFiles($matter);
                    $file = MatterOneDriveFolders::signedCopyAmong($files[$matter->getKey()], (string) $attachment->name, $attachment->size !== null ? (int) $attachment->size : null);
                } catch (\Throwable $e) {
                    $this->warn("#{$delivery->getKey()} {$delivery->name}: {$e->getMessage()}");

                    continue;
                }
            }

            if ($file === null) {
                $this->warn("#{$delivery->getKey()} {$delivery->name}: not found in the matter's signed-minutes folder".($matter ? " ({$matter->reference})" : ''));

                continue;
            }

            $delivery->update(['onedrive_url' => $file['webUrl']]);
            $found++;
        }

        $this->info("{$found} of {$cut->count()} links found again.");

        return self::SUCCESS;
    }
}
