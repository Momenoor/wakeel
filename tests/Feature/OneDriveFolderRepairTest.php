<?php

namespace Tests\Feature;

use App\Models\Court;
use App\Models\Matter;
use App\Models\MatterEmail;
use App\Models\MatterOneDriveFolder;
use App\Models\Party;
use App\Models\Setting;
use App\Models\Type;
use App\Services\MMS\MatterOneDriveFolders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeOneDrive;
use Tests\TestCase;

/**
 * A matter's folders put right by the super admin ("Fix folders"), made already or not: every subfolder of its
 * structure there — made, or renamed from another spelling of its name, what is kept in it following — the folder
 * itself given its standard name, and nothing else touched.
 */
class OneDriveFolderRepairTest extends TestCase
{
    use RefreshDatabase;

    private FakeOneDrive $drive;

    private Matter $matter;

    private Party $assistant;

    protected function setUp(): void
    {
        parent::setUp();

        config(['mail.mailers.microsoft-graph' => ['transport' => 'microsoft-graph', 'tenant_id' => 'tenant', 'client_id' => 'client', 'client_secret' => 'secret']]);
        Setting::clearCache();
        Setting::set(MatterOneDriveFolders::ENABLED, true, 'onedrive');
        Setting::set(MatterOneDriveFolders::ENABLED_AT, now()->subMinute()->toDateTimeString(), 'onedrive');
        Setting::set(MatterOneDriveFolders::SUBFOLDERS, "المراسلات الواردة\nالمستندات/من المدعي\nالتقارير", 'onedrive');

        $this->drive = (new FakeOneDrive)->install();
        $this->matter = Matter::factory()->create([
            'year' => 2026, 'number' => 123,
            'type_id' => Type::firstOrCreate(['name' => 'خبرة'])->id,
            'court_id' => Court::firstOrCreate(['name' => 'محاكم دبي'])->id,
        ]);
        $this->assistant = Party::factory()->assistant()->create(['name' => 'Amr', 'onedrive_email' => 'amr@firm.ae', 'onedrive_path' => 'Work/Matters']);
    }

    /** The matter's folder as it was made long ago: another name, a subfolder misspelt, others missing. */
    private function oldFolder(string $name = '123-2026 old'): string
    {
        $id = $this->drive->ensure('Work/Matters/'.$name);
        $this->drive->ensure('Work/Matters/'.$name.'/المراسلات  الوارده');
        $this->drive->ensure('Work/Matters/'.$name.'/المستندات');
        $this->drive->ensure('Work/Matters/'.$name.'/ملاحظات المساعد');

        MatterOneDriveFolder::create([
            'matter_id' => $this->matter->id, 'party_id' => $this->assistant->id, 'status' => MatterOneDriveFolder::CREATED,
            'drive_item_id' => $id, 'folder_name' => $name, 'web_url' => 'https://onedrive.test/'.rawurlencode($name),
        ]);

        return $id;
    }

    public function test_the_structure_is_made_right_and_the_folder_gets_its_standard_name(): void
    {
        $this->oldFolder();
        $email = MatterEmail::create(['matter_id' => $this->matter->id, 'direction' => MatterEmail::RECEIVED, 'sender_key' => 'office', 'subject' => 'x', 'at' => now(),
            'onedrive' => ['folder' => 'المراسلات  الوارده/2026-11-24', 'pdf' => 'saved']]);

        $done = app(MatterOneDriveFolders::class)->repair($this->matter);

        $standard = 'Work/Matters/2026-123 - خبرة - محاكم دبي';
        $this->assertSame(['التقارير', 'المراسلات الواردة', 'المستندات', 'ملاحظات المساعد'], $this->drive->names($standard));
        $this->assertSame(['من المدعي'], $this->drive->names($standard.'/المستندات'));
        $this->assertNull($this->drive->find('Work/Matters/123-2026 old'));

        // What was done, said; the folder the system keeps of the email followed its rename.
        $this->assertSame(['123-2026 old → 2026-123 - خبرة - محاكم دبي'], $done['main']);
        $this->assertSame(['Amr: المراسلات  الوارده → المراسلات الواردة'], $done['renamed']);
        $this->assertSame(['Amr: المستندات/من المدعي', 'Amr: التقارير'], $done['created']);
        $this->assertSame('المراسلات الواردة/2026-11-24', $email->fresh()->onedrive['folder']);
        $this->assertSame('2026-123 - خبرة - محاكم دبي', MatterOneDriveFolder::sole()->folder_name);

        // Right now: nothing more to do.
        $again = app(MatterOneDriveFolders::class)->repair($this->matter);
        $this->assertSame([[], [], []], [$again['main'], $again['renamed'], $again['created']]);
    }

    public function test_the_folders_name_is_left_when_asked_and_when_another_matter_of_the_case_shares_it(): void
    {
        $id = $this->oldFolder();

        app(MatterOneDriveFolders::class)->repair($this->matter, renameMain: false);
        $this->assertNotNull($this->drive->find('Work/Matters/123-2026 old/التقارير'));

        // Another phase of the case in the same main folder: its name is not this matter's to change.
        $other = Matter::factory()->create(['year' => 2026, 'number' => 123]);
        MatterOneDriveFolder::create(['matter_id' => $other->id, 'party_id' => $this->assistant->id, 'status' => MatterOneDriveFolder::CREATED, 'drive_item_id' => $id, 'folder_name' => '123-2026 old']);

        $done = app(MatterOneDriveFolders::class)->repair($this->matter);
        $this->assertSame([], $done['main']);
        $this->assertCount(1, $done['notes']);
        $this->assertNotNull($this->drive->find('Work/Matters/123-2026 old'));
    }

    public function test_spellings_are_compared_loosely_but_a_folder_of_the_structure_is_never_taken_for_another(): void
    {
        $this->assertSame(MatterOneDriveFolders::looseName('المراسلات الواردة'), MatterOneDriveFolders::looseName('01 - مراسلات  الوارده'));
        $this->assertSame(MatterOneDriveFolders::looseName('التقارير'), MatterOneDriveFolders::looseName('تقارير'));
        $this->assertNotSame(MatterOneDriveFolders::looseName('المراسلات الواردة'), MatterOneDriveFolders::looseName('المراسلات الصادرة'));
    }

    public function test_a_matter_with_no_folder_made_is_told_to_create_it(): void
    {
        $done = app(MatterOneDriveFolders::class)->repair($this->matter);

        $this->assertSame(0, $done['folders']);
        $this->assertCount(1, $done['notes']);
    }
}
