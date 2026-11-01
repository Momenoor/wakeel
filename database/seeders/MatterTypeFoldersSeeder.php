<?php

namespace Database\Seeders;

use App\Models\Type;
use App\Services\MMS\MatterOneDriveFolders;
use Illuminate\Database\Seeder;

/**
 * The office's folder structure given to every matter type at once (`php artisan db:seed --class=MatterTypeFoldersSeeder`),
 * by what the type is:
 *
 *  - a bankruptcy or insolvency type (إفلاس, إعسار in its name): a phase's folder with the system's, each side's by its
 *    capacity, the proceedings (correspondence there, the files with the party), the creditors' claims (a folder a
 *    creditor, numbered and named, each email with its files in a numbered folder inside, replies beside what they
 *    answer and new letters under صادر), the signed minutes and the periodic reports;
 *  - a liquidation or consultancy type (تصفية, استشار): left as it is;
 *  - any other: the system's, each side's by its capacity, the correspondence (sent under صادر, received under بريد
 *    وارد by day and number, the files with the party), the report to file and the signed minutes.
 *
 * Run again, it writes the same; a type's folders changed by hand since are written over.
 */
class MatterTypeFoldersSeeder extends Seeder
{
    /** A type these words name is a bankruptcy's. */
    private const BANKRUPTCY = ['إفلاس', 'افلاس', 'إعسار', 'اعسار'];

    /** A type these words name is left as it is. */
    private const LEFT_ALONE = ['تصفية', 'استشار'];

    public function run(): void
    {
        foreach (Type::query()->orderBy('id')->get() as $type) {
            $fields = self::foldersFor((string) $type->name);

            if ($fields === null) {
                $this->command?->line("  {$type->name}: left as it is");

                continue;
            }

            $type->forceFill([...array_fill_keys(Type::FOLDER_FIELDS, null), ...$fields])->save();
            $this->command?->info("  {$type->name}: ".(isset($fields['onedrive_received_creditors']) ? 'bankruptcy folders' : 'standard folders'));
        }
    }

    /**
     * The folder fields for a type by its name — null for one left as it is.
     *
     * @return array<string, string>|null
     */
    public static function foldersFor(string $name): ?array
    {
        $has = fn (array $words): bool => collect($words)->contains(fn (string $word): bool => str_contains($name, $word));

        if ($has(self::LEFT_ALONE)) {
            return null;
        }

        return $has(self::BANKRUPTCY) ? self::bankruptcy() : self::standard();
    }

    /**
     * @return array<string, string>
     */
    public static function bankruptcy(): array
    {
        $phase = MatterOneDriveFolders::PHASE;
        $claim = $phase.'/05 مطالبات الدائنين/{{creditor.number}} {{creditor}}';

        return [
            'onedrive_subfolders' => implode("\n", [
                $phase.'/01 السيستم',
                $phase.'/02 {{matter.plaintiff_capacity}}',
                $phase.'/03 {{matter.defendant_capacity}}',
                $phase.'/04 الإجراءات',
                $phase.'/05 مطالبات الدائنين',
                $phase.'/06 محاضر موقعة',
                $phase.'/07 تقارير دورية',
            ]),
            // Every party's correspondence in the proceedings — received by day and number, sent under صادر — its files with the party.
            'onedrive_received_emails' => $phase.'/04 الإجراءات/بريد وارد/{{day}}/{{serial}}',
            'onedrive_attachments_plaintiff' => $phase.'/02 {{matter.plaintiff_capacity}}',
            'onedrive_attachments_defendant' => $phase.'/03 {{matter.defendant_capacity}}',
            'onedrive_sent_emails' => $phase.'/04 الإجراءات/صادر',
            // A creditor's: its own folder, numbered and named; each email with its files in a numbered folder of its
            // own; what we send it anew under صادر (a reply goes beside the email it answers by itself).
            'onedrive_received_creditors' => $claim.'/{{serial}}',
            'onedrive_attachments_creditors' => $claim.'/{{serial}}',
            'onedrive_sent_creditors' => $claim.'/صادر',
            'onedrive_signed_minutes' => $phase.'/06 محاضر موقعة',
            'onedrive_periodic_reports' => $phase.'/07 تقارير دورية/تقرير دوري رقم {{number}}',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function standard(): array
    {
        return [
            'onedrive_subfolders' => implode("\n", [
                '01 السيستم',
                '02 {{matter.plaintiff_capacity}}',
                '03 {{matter.defendant_capacity}}',
                '04 المراسلات',
                '05 تقرير للرفع',
                '06 محاضر موقعة',
            ]),
            'onedrive_received_emails' => '04 المراسلات/بريد وارد/{{day}}/{{serial}}',
            'onedrive_attachments_plaintiff' => '02 {{matter.plaintiff_capacity}}',
            'onedrive_attachments_defendant' => '03 {{matter.defendant_capacity}}',
            'onedrive_sent_emails' => '04 المراسلات/صادر',
            'onedrive_signed_minutes' => '06 محاضر موقعة',
        ];
    }
}
