<?php

namespace Tests\Feature;

use App\Models\Matter;
use App\Models\MatterCreditor;
use App\Models\MatterParty;
use App\Models\Party;
use App\Models\Setting;
use App\Models\Type;
use App\Services\MMS\MatterOneDriveFolders;
use App\Services\MMS\ReceivedEmailFiling;
use Database\Seeders\MatterTypeFoldersSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The office's folder structure given to every matter type at once, by what the type is — and what it makes for
 * a matter's emails and files.
 */
class MatterTypeFoldersSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_type_gets_the_folders_of_its_kind_and_liquidation_and_consultancy_are_left_alone(): void
    {
        $bankruptcy = Type::factory()->create(['name' => 'إجراءات إفلاس', 'has_creditors' => true, 'has_phases' => true, 'phases' => ['المرحلة الأولى', 'المرحلة الثانية']]);
        $insolvency = Type::factory()->create(['name' => 'إعسار']);
        $expertise = Type::factory()->create(['name' => 'خبرة حسابية']);
        $liquidation = Type::factory()->create(['name' => 'تصفية شركة', 'onedrive_subfolders' => 'X']);
        $consultancy = Type::factory()->create(['name' => 'استشاري', 'onedrive_subfolders' => 'Y']);
        Setting::clearCache();

        $this->seed(MatterTypeFoldersSeeder::class);

        foreach ([$bankruptcy, $insolvency] as $type) {
            $this->assertSame(MatterTypeFoldersSeeder::bankruptcy()['onedrive_subfolders'], $type->fresh()->onedrive_subfolders);
            $this->assertSame('{{matter.phase}}/05 مطالبات الدائنين/{{creditor.number}} {{creditor}}/صادر', $type->fresh()->onedrive_sent_creditors);
        }
        $this->assertSame(MatterTypeFoldersSeeder::standard()['onedrive_subfolders'], $expertise->fresh()->onedrive_subfolders);
        $this->assertNull($expertise->fresh()->onedrive_received_creditors);
        $this->assertSame(['X', 'Y'], [$liquidation->fresh()->onedrive_subfolders, $consultancy->fresh()->onedrive_subfolders]);

        // The two kinds are two groups of types alike — beside the two left with folders of their own.
        $this->assertCount(4, Type::folderGroups());
        $this->assertEqualsCanonicalizing([$bankruptcy->id, $insolvency->id], Type::folderGroups()->first(fn ($group) => $group->contains('id', $bankruptcy->id))->pluck('id')->all());
    }

    public function test_the_bankruptcy_folders_file_a_creditors_email_and_its_files_in_a_numbered_folder_of_the_claim(): void
    {
        $type = Type::factory()->create(['name' => 'إجراءات إفلاس', 'has_creditors' => true, 'has_phases' => true, 'phases' => ['المرحلة الأولى']]);
        $type->forceFill(MatterTypeFoldersSeeder::bankruptcy())->save();
        $matter = Matter::factory()->create(['type_id' => $type->id, 'phase' => 'المرحلة الأولى']);
        MatterParty::create(['matter_id' => $matter->id, 'role' => 'party', 'type' => 'plaintiff', 'party_id' => Party::factory()->create(['name' => 'المدين', 'email' => ['debtor@x.ae']])->id]);
        $creditor = MatterCreditor::assign($matter, null, 'بنك المشرق للتمويل الإسلامي والاستثمار ش.م.ع', 'claims@mashreq.ae');
        $at = Carbon::parse('2026-10-16 10:00', config('app.timezone'));

        // The claim's folder: number and name, the name no longer than 25 characters; the email and its files in its
        // numbered folder; what we send it anew under صادر.
        $claim = 'المرحلة الأولى/05 مطالبات الدائنين/001 بنك المشرق للتمويل الإسلا…';
        $this->assertSame($claim.'/1', MatterOneDriveFolders::receivedEmailsFolderFor($matter, $at, ReceivedEmailFiling::CREDITOR, 'claims@mashreq.ae'));
        $this->assertSame($claim.'/1', MatterOneDriveFolders::attachmentsFolderFor($matter, $at, ReceivedEmailFiling::CREDITOR, 'claims@mashreq.ae', 'بنك المشرق'));
        $this->assertSame($claim.'/صادر', MatterOneDriveFolders::sentEmailsFolderFor($matter, $at, ['claims@mashreq.ae']));

        // A party's: in the proceedings by day and number, its files with the party; sent under صادر.
        $this->assertSame('المرحلة الأولى/04 الإجراءات/بريد وارد/2026-10-16/1', MatterOneDriveFolders::receivedEmailsFolderFor($matter, $at, ReceivedEmailFiling::PLAINTIFF, 'debtor@x.ae'));
        $this->assertSame('المرحلة الأولى/02 '.Type::sideLabel($type, 'plaintiff'), MatterOneDriveFolders::attachmentsFolderFor($matter, $at, ReceivedEmailFiling::PLAINTIFF, 'debtor@x.ae', 'المدين'));
        $this->assertSame('المرحلة الأولى/04 الإجراءات/صادر', MatterOneDriveFolders::sentEmailsFolderFor($matter, $at, ['debtor@x.ae']));
        $this->assertSame('المرحلة الأولى/06 محاضر موقعة', MatterOneDriveFolders::signedMinutesFolderFor($matter));
        $this->assertContains('المرحلة الأولى/05 مطالبات الدائنين', MatterOneDriveFolders::subfoldersFor($matter));
    }

    public function test_the_standard_folders_file_a_partys_email_by_day_and_number_and_its_files_with_the_party(): void
    {
        $type = Type::factory()->create(['name' => 'خبرة']);
        $type->forceFill(MatterTypeFoldersSeeder::standard())->save();
        $matter = Matter::factory()->create(['type_id' => $type->id]);
        MatterParty::create(['matter_id' => $matter->id, 'role' => 'party', 'type' => 'defendant', 'party_id' => Party::factory()->create(['name' => 'شركة النور', 'email' => ['noor@x.ae']])->id]);
        $at = Carbon::parse('2026-10-16 10:00', config('app.timezone'));

        $this->assertSame('04 المراسلات/بريد وارد/2026-10-16/1', MatterOneDriveFolders::receivedEmailsFolderFor($matter, $at, ReceivedEmailFiling::DEFENDANT, 'noor@x.ae'));
        $this->assertSame('03 '.Type::sideLabel($type, 'defendant'), MatterOneDriveFolders::attachmentsFolderFor($matter, $at, ReceivedEmailFiling::DEFENDANT, 'noor@x.ae', 'شركة النور'));
        // Anyone else's files: beside the email, as always.
        $this->assertSame('', MatterOneDriveFolders::attachmentsFolderFor($matter, $at, ReceivedEmailFiling::GENERAL, 'court@x.ae', 'المحكمة'));
        $this->assertSame('04 المراسلات/صادر', MatterOneDriveFolders::sentEmailsFolderFor($matter, $at, ['noor@x.ae']));
        $this->assertSame(['01 السيستم', '02 '.Type::sideLabel($type, 'plaintiff'), '03 '.Type::sideLabel($type, 'defendant'), '04 المراسلات', '05 تقرير للرفع', '06 محاضر موقعة'], MatterOneDriveFolders::subfoldersFor($matter));
    }
}
