<?php

namespace App\Services\MMS;

use App\Jobs\MovePeriodicReportFiles;
use App\Models\BulkMailCampaign;
use App\Models\BulkMailRecipient;
use App\Models\Matter;
use App\Models\MatterEmail;
use App\Models\MatterMinutes;
use App\Models\MatterPeriodicReport;
use App\Models\MatterProgress;
use App\Models\MinutesDelivery;
use App\Models\Setting;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A matter's periodic reports: steps of its progress chosen for one — no. 1, 2 … — their files moved into its folder
 * in the matter's OneDrive (the emails sent and come in: PDF, original, attachments; signed copies of minutes), to go
 * with it, and the steps marked as in it, apart from those after it. Taken out of it: back where they were.
 */
final class PeriodicReports
{
    /** A report's folder: the setting, and the matter type's column. */
    public const SETTING = 'onedrive_periodic_reports_folder';

    public const COLUMN = 'onedrive_periodic_reports';

    public const DEFAULT_FOLDER = 'تقرير دوري رقم {{number}}';

    public function __construct(private readonly MatterOneDriveFolders $folders) {}

    /** The folder structure for a report of this matter: its type's own, else the setting, else the default. */
    public static function template(?Matter $matter = null): string
    {
        $own = $matter ? trim((string) $matter->loadMissing('type')->type?->getAttribute(self::COLUMN)) : '';

        return $own !== '' ? $own : (trim((string) Setting::get(self::SETTING, '')) ?: self::DEFAULT_FOLDER);
    }

    /** A report's folder in the matter's OneDrive folder: its structure with the matter's placeholders and {{number}}. */
    public static function folderFor(Matter $matter, int $number): string
    {
        return MatterOneDriveFolders::resolvePath(self::template($matter), $matter, now(), str_replace('{{number}}', (string) $number, self::DEFAULT_FOLDER), ['number' => (string) $number]);
    }

    /**
     * Steps of a matter put in a report of it — a new one (the next number), or one it has — their files moved there
     * on the queue. The report.
     *
     * @param  iterable<MatterProgress>  $steps
     */
    public static function add(Matter $matter, iterable $steps, ?int $reportId = null, ?int $userId = null): MatterPeriodicReport
    {
        $report = $reportId
            ? $matter->periodicReports()->findOrFail($reportId)
            : DB::transaction(function () use ($matter, $userId): MatterPeriodicReport {
                $number = (int) $matter->periodicReports()->lockForUpdate()->max('number') + 1;

                return $matter->periodicReports()->create(['number' => $number, 'folder' => self::folderFor($matter, $number), 'user_id' => $userId]);
            });

        $ids = self::ids(collect($steps)->filter(fn (MatterProgress $step): bool => (int) $step->matter_id === (int) $matter->getKey()));
        MatterProgress::query()->whereKey($ids)->update(['periodic_report_id' => $report->getKey()]);

        MovePeriodicReportFiles::dispatch($report->getKey(), $ids, true, $userId);

        return $report;
    }

    /**
     * Steps taken out of the reports they are in, their files moved back on the queue.
     *
     * @param  iterable<MatterProgress>  $steps
     */
    public static function remove(iterable $steps, ?int $userId = null): void
    {
        collect($steps)->filter(fn (MatterProgress $step): bool => $step->periodic_report_id !== null)
            ->groupBy('periodic_report_id')
            ->each(function (Collection $inReport, $reportId) use ($userId): void {
                // Out of it first: what is left in it keeps its files (moveOut()).
                MatterProgress::query()->whereKey(self::ids($inReport))->update(['periodic_report_id' => null]);
                MovePeriodicReportFiles::dispatch((int) $reportId, self::ids($inReport), false, $userId);
            });
    }

    /**
     * @param  Collection<int, MatterProgress>  $steps
     * @return list<int>
     */
    private static function ids(Collection $steps): array
    {
        return $steps->map(fn (MatterProgress $step): int => (int) $step->getKey())->values()->all();
    }

    /**
     * The steps' files into the report's folder: their emails not in a report yet, where each was noted, and the
     * signed copies of their minutes. How many of each moved.
     *
     * @param  list<int>  $stepIds
     * @return array{emails: int, minutes: int}
     */
    public function moveIn(MatterPeriodicReport $report, array $stepIds): array
    {
        $matter = $report->matter;
        $steps = $matter->progress()->whereKey($stepIds)->get();
        $emails = self::emailsOf($matter, $steps)->filter(fn (MatterEmail $email): bool => (((array) $email->onedrive)['report'] ?? null) === null);
        $moved = 0;

        foreach ($emails as $email) {
            $before = MatterOneDriveFolders::emailFolder($email, $matter);
            $moved += $this->folders->moveEmailWithin($email, $matter, (string) $report->folder, ['report' => $report->getKey(), 'before_report' => $before]) ? 1 : 0;
        }

        $minutes = $this->moveSignedCopies($matter, $steps, MatterOneDriveFolders::signedMinutesFolderFor($matter), (string) $report->folder);

        return ['emails' => $moved, 'minutes' => $minutes];
    }

    /**
     * The steps' files out of the report's folder, back where each was — but those another step still in the report
     * has too (a letter's, sent twice). How many of each moved.
     *
     * @param  list<int>  $stepIds
     * @return array{emails: int, minutes: int}
     */
    public function moveOut(MatterPeriodicReport $report, array $stepIds): array
    {
        $matter = $report->matter;
        $remaining = $report->steps()->get(['id', 'source_type', 'source_id'])
            ->map(fn (MatterProgress $step): string => $step->source_type.':'.$step->source_id)->flip();
        $steps = $matter->progress()->whereKey($stepIds)->get()
            ->reject(fn (MatterProgress $step): bool => $step->source_type !== null && $remaining->has($step->source_type.':'.$step->source_id));
        $moved = 0;

        foreach (self::emailsOf($matter, $steps) as $email) {
            $kept = (array) $email->onedrive;

            if ((int) ($kept['report'] ?? 0) !== (int) $report->getKey()) {
                continue;
            }

            $moved += $this->folders->moveEmailWithin($email, $matter, (string) ($kept['before_report'] ?? ''), ['report' => null, 'before_report' => null]) ? 1 : 0;
        }

        $minutes = $this->moveSignedCopies($matter, $steps, (string) $report->folder, MatterOneDriveFolders::signedMinutesFolderFor($matter));

        return ['emails' => $moved, 'minutes' => $minutes];
    }

    /**
     * The emails the steps are of: one come in (the step's own), a letter's and minutes' sent, a bulk mail's sent
     * to each of its recipients — on the matter. A step added by hand has none.
     *
     * @param  Collection<int, MatterProgress>  $steps
     * @return Collection<int, MatterEmail>
     */
    public static function emailsOf(Matter $matter, Collection $steps): Collection
    {
        $emails = new Collection;

        foreach ($steps->filter(fn (MatterProgress $step): bool => filled($step->source_type))->groupBy('source_type') as $type => $group) {
            $ids = $group->pluck('source_id')->unique()->values()->all();
            $query = match ($type) {
                (new MatterEmail)->getMorphClass() => MatterEmail::query()->whereKey($ids),
                (new BulkMailCampaign)->getMorphClass() => MatterEmail::query()->where('source_type', (new BulkMailRecipient)->getMorphClass())
                    ->whereIn('source_id', BulkMailRecipient::query()->whereIn('campaign_id', $ids)->select('id')),
                default => MatterEmail::query()->where('source_type', $type)->whereIn('source_id', $ids),
            };

            $emails = $emails->merge($query->where('matter_id', $matter->getKey())->get());
        }

        return $emails->unique(fn (MatterEmail $email): int => (int) $email->getKey())->values();
    }

    /**
     * The signed copies of the steps' minutes, from one folder of the matter's to another, their links kept up to
     * date. How many moved.
     *
     * @param  Collection<int, MatterProgress>  $steps
     */
    private function moveSignedCopies(Matter $matter, Collection $steps, string $from, string $to): int
    {
        $minutes = $steps->where('source_type', (new MatterMinutes)->getMorphClass())->pluck('source_id')->unique()->values()->all();
        $deliveries = $minutes === [] ? collect() : MinutesDelivery::query()->whereIn('matter_minutes_id', $minutes)->whereNotNull('onedrive_url')->get(['id', 'onedrive_url']);

        if ($deliveries->isEmpty()) {
            return 0;
        }

        $moved = $this->folders->moveFilesByUrl($matter, $deliveries->pluck('onedrive_url')->unique()->values()->all(), $from, $to);

        foreach ($moved as $old => $new) {
            MinutesDelivery::query()->where('onedrive_url', $old)->whereIn('matter_minutes_id', $minutes)->update(['onedrive_url' => $new]);
        }

        return count($moved);
    }
}
