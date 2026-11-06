<?php

namespace Tests\Feature;

use App\Enums\BulkMailCampaignStatus;
use App\Filament\Mms\Imports\BulkMailRecipientImporter;
use App\Filament\Mms\Resources\BulkMailCampaigns\Pages\ViewBulkMailCampaign;
use App\Filament\Mms\Resources\BulkMailCampaigns\RelationManagers\RecipientsRelationManager;
use App\Filament\Mms\Resources\Matters\Pages\ViewMatter;
use App\Filament\Mms\Resources\Matters\RelationManagers\EmailsRelationManager;
use App\Filament\Support\CreditorFields;
use App\Livewire\MatterContacts;
use App\Models\BulkMailCampaign;
use App\Models\Matter;
use App\Models\MatterCreditor;
use App\Models\MatterEmail;
use App\Models\MatterOneDriveFolder;
use App\Models\MatterParty;
use App\Models\Party;
use App\Models\Setting;
use App\Models\Type;
use App\Models\User;
use App\Services\MMS\ContactLinker;
use App\Services\MMS\CreditorFirstMails;
use App\Services\MMS\MatterContactsExport;
use App\Services\MMS\MatterCreditors;
use App\Services\MMS\MatterOneDriveFolders;
use App\Services\MMS\OneDriveClient;
use App\Services\MMS\ReceivedEmailFiling;
use App\Services\MMS\SentEmailArchive;
use Filament\Actions\Imports\Models\Import;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\MemoryOneDrive;
use Tests\TestCase;

/**
 * A bankruptcy's creditors — no parties of it — on its Contacts tab, each with its addresses and its lawyer's or
 * agent's: their emails saved in a folder of the creditor's, and the list exported for a bulk mail to them all.
 */
class MatterCreditorsTest extends TestCase
{
    use RefreshDatabase;

    private Matter $matter;

    private MemoryOneDrive $oneDrive;

    protected function setUp(): void
    {
        parent::setUp();

        Gate::before(fn () => true);
        Filament::setCurrentPanel(Filament::getPanel('mms'));
        $this->actingAs(User::factory()->create());
        app()->setLocale('ar');

        $this->oneDrive = new MemoryOneDrive('root-986');
        $this->app->instance(OneDriveClient::class, $this->oneDrive);

        $this->matter = Matter::factory()->create(['number' => '986', 'year' => '2026', 'type_id' => Type::factory()->create(['name' => 'اجراءات افلاس', 'has_creditors' => true])->id]);
        $plaintiff = Party::factory()->create(['name' => 'شركة المهاد', 'email' => ['info@mahad.ae']]);
        MatterParty::create(['matter_id' => $this->matter->id, 'party_id' => $plaintiff->id, 'role' => 'party', 'type' => 'plaintiff']);
        MatterOneDriveFolder::create([
            'matter_id' => $this->matter->id, 'folder_name' => '986-2026', 'status' => MatterOneDriveFolder::CREATED, 'drive_item_id' => 'root-986',
            'party_id' => Party::factory()->assistant()->create(['onedrive_email' => 'amr@firm.ae'])->id,
        ]);

        Setting::set(MatterOneDriveFolders::RECEIVED_EMAILS, '04 المراسلات', 'onedrive');
        Setting::set(ReceivedEmailFiling::FOLDER_SETTINGS[ReceivedEmailFiling::CREDITOR], '07 مطالبات الدائنين/{{creditor}}', 'onedrive');
        Setting::set(ReceivedEmailFiling::NAME_SETTINGS[ReceivedEmailFiling::CREDITOR], '{{date}} — {{creditor}} — {{side}} — {{subject}}', 'onedrive');
        Setting::clearCache();
    }

    /** An email come in on the matter, its PDF and original in the general folder of OneDrive. */
    private function received(string $from, string $stem): MatterEmail
    {
        $this->oneDrive->put('04 المراسلات/'.$stem.'.pdf');
        $this->oneDrive->put('04 المراسلات/'.$stem.'.eml');

        return MatterEmail::create([
            'matter_id' => $this->matter->id, 'direction' => MatterEmail::RECEIVED, 'status' => MatterEmail::FILED, 'sender_key' => 'iflas',
            'message_id' => uniqid().'@x.ae', 'subject' => 'مطالبة', 'from' => $from, 'at' => now(),
            'files' => [['name' => $stem.'.pdf', 'path' => null]], 'onedrive' => ['pdf' => true, 'folder' => '04 المراسلات'],
        ]);
    }

    public function test_a_creditor_and_its_agent_are_filed_in_its_folder_named_by_it(): void
    {
        $creditor = MatterCreditor::assign($this->matter, null, 'بنك المشرق', 'Claims@Mashreq.ae');
        MatterCreditor::assign($this->matter, $creditor->id, null, 'legal@mashreq.ae');
        MatterCreditor::assign($this->matter, $creditor->id, null, 'lawyer@hashmi.ae', true, 'مكتب الهاشمي');

        // Another address: to the same creditor, not a second one.
        $this->assertSame(1, $this->matter->creditors()->count());
        $this->assertSame(3, $creditor->addresses()->count());

        foreach (['claims@mashreq.ae', 'legal@mashreq.ae', 'lawyer@hashmi.ae'] as $address) {
            $this->assertSame(ReceivedEmailFiling::CREDITOR, ReceivedEmailFiling::side($this->matter, $address));
            $this->assertSame('07 مطالبات الدائنين/بنك المشرق', MatterOneDriveFolders::receivedEmailsFolderFor($this->matter, now(), ReceivedEmailFiling::CREDITOR, $address));
        }

        // A party's side first; anyone else the general one.
        $this->assertSame(ReceivedEmailFiling::PLAINTIFF, ReceivedEmailFiling::side($this->matter, 'info@mahad.ae'));
        $this->assertSame(ReceivedEmailFiling::GENERAL, ReceivedEmailFiling::side($this->matter, 'court@dubai.gov.ae'));

        $at = Carbon::parse('2026-10-30 10:00', config('app.timezone'));
        $this->assertSame('2026-10-30 — بنك المشرق — وكيل الدائن — مطالبة', ReceivedEmailFiling::name($this->matter, ReceivedEmailFiling::CREDITOR, 'مكتب الهاشمي', 'مطالبة', $at, false, 'lawyer@hashmi.ae'));
        $this->assertSame('2026-10-30 — بنك المشرق — الدائن — مطالبة', ReceivedEmailFiling::name($this->matter, ReceivedEmailFiling::CREDITOR, 'بنك المشرق', 'مطالبة', $at, false, 'claims@mashreq.ae'));
    }

    public function test_the_sender_of_an_email_made_a_creditors_agent_has_its_emails_moved_to_the_creditors_folder(): void
    {
        $creditor = MatterCreditor::assign($this->matter, null, 'بنك المشرق', 'claims@mashreq.ae');
        $first = $this->received('مكتب الهاشمي <lawyer@hashmi.ae>', 'وارد 1');
        $second = $this->received('lawyer@hashmi.ae', 'وارد 2');
        $other = $this->received('court@dubai.gov.ae', 'وارد 3');

        Livewire::test(EmailsRelationManager::class, ['ownerRecord' => $this->matter, 'pageClass' => ViewMatter::class])
            ->callAction(TestAction::make('creditor')->table($first), ['creditor_id' => $creditor->id, 'agent' => true, 'agent_name' => 'مكتب الهاشمي'])
            ->assertHasNoFormErrors();

        $address = $creditor->addresses()->where('email', 'lawyer@hashmi.ae')->sole();
        $this->assertTrue($address->agent);
        $this->assertSame('مكتب الهاشمي', $address->agent_name);
        $this->assertSame([
            '04 المراسلات/وارد 3.eml', '04 المراسلات/وارد 3.pdf',
            '07 مطالبات الدائنين/بنك المشرق/وارد 1.eml', '07 مطالبات الدائنين/بنك المشرق/وارد 1.pdf',
            '07 مطالبات الدائنين/بنك المشرق/وارد 2.eml', '07 مطالبات الدائنين/بنك المشرق/وارد 2.pdf',
        ], $this->oneDrive->files());
        $this->assertSame('07 مطالبات الدائنين/بنك المشرق', $second->fresh()->onedrive['folder']);
        $this->assertSame('04 المراسلات', $other->fresh()->onedrive['folder']);
    }

    public function test_the_files_an_email_came_with_kept_in_an_attachments_folder_follow_it_to_the_creditors(): void
    {
        $creditor = MatterCreditor::assign($this->matter, null, 'بنك المشرق', 'claims@mashreq.ae');
        $email = $this->received('lawyer@hashmi.ae', 'وارد 1');
        $email->update([
            'files' => [['name' => 'وارد 1.pdf', 'path' => null], ['name' => 'مطالبة.pdf', 'path' => null], ['name' => 'عقد.pdf', 'path' => null]],
            'onedrive' => [...$email->onedrive, 'attachments_folder' => '02 المستندات'],
        ]);
        $this->oneDrive->put('02 المستندات/مطالبة.pdf');
        $this->oneDrive->put('02 المستندات/عقد.pdf');
        // Another's, in the same attachments folder: stays.
        $this->oneDrive->put('02 المستندات/حكم.pdf');
        Setting::set(ReceivedEmailFiling::ATTACHMENTS_SETTINGS[ReceivedEmailFiling::CREDITOR], '07 مطالبات الدائنين/{{creditor}}/المستندات', 'onedrive');
        Setting::clearCache();

        MatterCreditor::assign($this->matter, $creditor->id, null, 'lawyer@hashmi.ae', true, 'مكتب الهاشمي');
        $this->assertSame(1, app(MatterCreditors::class)->refile($this->matter, ['lawyer@hashmi.ae']));

        $this->assertSame([
            '02 المستندات/حكم.pdf',
            '07 مطالبات الدائنين/بنك المشرق/المستندات/عقد.pdf', '07 مطالبات الدائنين/بنك المشرق/المستندات/مطالبة.pdf',
            '07 مطالبات الدائنين/بنك المشرق/وارد 1.eml', '07 مطالبات الدائنين/بنك المشرق/وارد 1.pdf',
        ], $this->oneDrive->files());
        $this->assertSame('07 مطالبات الدائنين/بنك المشرق/المستندات', $email->fresh()->onedrive['attachments_folder']);

        // No attachments folder set for the creditors: beside its PDF, in a folder of its name.
        $other = MatterCreditor::assign($this->matter, null, 'بنك دبي', 'claims@dib.ae');
        $second = $this->received('agent@dib.ae', 'وارد 2');
        $second->update([
            'files' => [['name' => 'وارد 2.pdf', 'path' => null], ['name' => 'كشف.pdf', 'path' => null]],
            'onedrive' => [...$second->onedrive, 'attachments_folder' => '02 المستندات'],
        ]);
        $this->oneDrive->put('02 المستندات/كشف.pdf');
        Setting::set(ReceivedEmailFiling::ATTACHMENTS_SETTINGS[ReceivedEmailFiling::CREDITOR], '', 'onedrive');
        Setting::clearCache();

        MatterCreditor::assign($this->matter, $other->id, null, 'agent@dib.ae', true);
        $this->assertSame(1, app(MatterCreditors::class)->refile($this->matter, ['agent@dib.ae']));

        $this->assertContains('07 مطالبات الدائنين/بنك دبي/وارد 2/كشف.pdf', $this->oneDrive->files());
        $this->assertNotContains('02 المستندات/كشف.pdf', $this->oneDrive->files());
        $this->assertArrayNotHasKey('attachments_folder', $second->fresh()->onedrive);
    }

    public function test_the_contacts_tab_lists_creditors_and_their_agents_and_exports_them_for_bulk_mail(): void
    {
        Livewire::test(MatterContacts::class, ['matter' => $this->matter])
            ->callTableAction('addCreditor', data: ['name' => 'بنك المشرق', 'claim_amount' => '250000', 'emails' => ['claims@mashreq.ae'], 'phone' => ['043334444']])
            ->assertHasNoTableActionErrors();
        $creditor = $this->matter->creditors()->sole();
        $this->assertSame('250000.00', $creditor->claim_amount);

        $tab = Livewire::test(MatterContacts::class, ['matter' => $this->matter]);
        $tab->callTableAction('addAgent', 'c:'.$creditor->id, ['agent_name' => 'مكتب الهاشمي', 'emails' => ['lawyer@hashmi.ae', 'hashmi@gmail.com']])
            ->assertHasNoTableActionErrors();

        $rows = collect((new MatterContactsExport($this->matter))->rows())->keyBy('name');
        $this->assertSame('الدائن', $rows['بنك المشرق']['capacity']);
        $this->assertSame('250000.00', $rows['بنك المشرق']['claim']);
        $this->assertSame('وكيل الدائن', $rows['مكتب الهاشمي']['capacity']);
        $this->assertSame('بنك المشرق', $rows['مكتب الهاشمي']['for']);
        $this->assertTrue($rows['مكتب الهاشمي']['agent']);

        // Only creditors and their agents.
        Livewire::test(MatterContacts::class, ['matter' => $this->matter])
            ->filterTable('creditors', true)
            ->assertCanSeeTableRecords(['c:'.$creditor->id])
            ->assertCanNotSeeTableRecords(['p:'.MatterParty::query()->where('matter_id', $this->matter->id)->where('type', 'plaintiff')->value('party_id')]);

        // For a bulk mail: to the creditor and its agent alike.
        $this->assertSame(['name', 'email', 'agent', 'phone', 'claim_amount'], MatterCreditors::BULK_MAIL_HEADINGS);
        $this->assertSame([['بنك المشرق', 'claims@mashreq.ae, lawyer@hashmi.ae, hashmi@gmail.com', 'مكتب الهاشمي', '043334444', '250000.00', '1', '', '', '']], MatterCreditors::bulkMailRows($this->matter));
    }

    private function campaign(): BulkMailCampaign
    {
        return BulkMailCampaign::create([
            'name' => 'إشعار الدائنين', 'subject' => 'إشعار', 'body' => '<p>{{claim_amount}}</p>', 'from_sender_key' => 'iflas',
            'matter_id' => $this->matter->id, 'daily_send_limit' => 60, 'status' => BulkMailCampaignStatus::Draft, 'created_by' => User::factory()->create()->id,
        ]);
    }

    /** A recipients list imported into the campaign, line by line. */
    private function import(BulkMailCampaign $campaign, array $rows, bool $creditors = true): void
    {
        $import = Import::create(['file_name' => 'creditors.xlsx', 'file_path' => 'creditors.xlsx', 'importer' => BulkMailRecipientImporter::class, 'total_rows' => count($rows), 'user_id' => auth()->id()]);
        $importer = new BulkMailRecipientImporter($import, ['name' => 'name', 'email' => 'email'], ['campaign_id' => $campaign->id, BulkMailRecipientImporter::CREDITORS => $creditors]);

        foreach ($rows as $row) {
            $importer($row);
        }
    }

    public function test_a_creditors_list_imported_into_a_campaign_is_merged_into_the_matters_creditors(): void
    {
        $mashreq = MatterCreditor::assign($this->matter, null, 'بنك المشرق', 'claims@mashreq.ae');
        $mashreq->update(['claim_amount' => 250000]);
        $amal = MatterCreditor::assign($this->matter, null, 'مؤسسة الأمل', 'info@amal.ae');

        $this->import($this->campaign(), [
            // Known by its address: its new one added, its claim as the list has it now.
            ['name' => 'Mashreq Bank', 'email' => 'claims@mashreq.ae, legal@mashreq.ae', 'claim_amount' => '300,000'],
            // Known by its name, written another way.
            ['name' => 'مؤسسة الامل', 'email' => 'amal.claims@gmail.com', 'claim_amount' => '٥٠٠٠'],
            // New.
            ['name' => 'شركة النور', 'email' => 'noor@noor.ae', 'claim_amount' => '', 'phone' => '0501234567'],
        ]);

        $this->assertSame(3, $this->matter->creditors()->count());
        $this->assertSame(['بنك المشرق', '300000.00'], [$mashreq->fresh()->name, $mashreq->fresh()->claim_amount]);
        $this->assertEqualsCanonicalizing(['claims@mashreq.ae', 'legal@mashreq.ae'], $mashreq->addresses()->pluck('email')->all());
        $this->assertSame('5000.00', $amal->fresh()->claim_amount);
        $this->assertSame(['info@amal.ae', 'amal.claims@gmail.com'], $amal->addresses()->orderBy('id')->pluck('email')->all());
        $noor = $this->matter->creditors()->where('name', 'شركة النور')->sole();
        $this->assertSame([null, ['0501234567']], [$noor->claim_amount, $noor->phones]);

        // Turned off — a list that is not of creditors: the matter's creditors as they were.
        $this->import($this->campaign(), [['name' => 'شركة المهاد', 'email' => 'info@mahad.ae']], creditors: false);
        $this->assertSame(3, $this->matter->creditors()->count());
    }

    public function test_the_matters_creditors_are_added_to_a_campaign_but_those_already_on_it(): void
    {
        $mashreq = MatterCreditor::assign($this->matter, null, 'بنك المشرق', 'claims@mashreq.ae');
        MatterCreditor::assign($this->matter, $mashreq->id, null, 'lawyer@hashmi.ae', true, 'مكتب الهاشمي');
        $mashreq->update(['claim_amount' => 250000]);
        MatterCreditor::assign($this->matter, null, 'مؤسسة الأمل', 'info@amal.ae');
        $this->matter->creditors()->create(['name' => 'بلا بريد']);

        $campaign = $this->campaign();
        $campaign->recipients()->create(['name' => 'Amal', 'email' => ['INFO@amal.ae'], 'status' => 'pending']);

        Livewire::test(RecipientsRelationManager::class, ['ownerRecord' => $campaign, 'pageClass' => ViewBulkMailCampaign::class])
            ->callTableAction('fromCreditors')
            ->assertHasNoTableActionErrors();

        $added = $campaign->recipients()->where('name', 'بنك المشرق')->sole();
        $this->assertSame(['claims@mashreq.ae', 'lawyer@hashmi.ae'], $added->email);
        $this->assertSame(['agent' => 'مكتب الهاشمي', 'claim_amount' => '250000.00', 'number' => '1'], $added->placeholders);
        $this->assertSame(2, $campaign->recipients()->count());
        $this->assertSame(2, $campaign->fresh()->total_recipients);
    }

    public function test_a_matter_of_a_type_without_creditors_has_none_of_it(): void
    {
        $this->matter->type->update(['has_creditors' => false]);

        Livewire::test(MatterContacts::class, ['matter' => $this->matter->fresh()])
            ->assertTableActionHidden('addCreditor')
            ->assertTableActionHidden('exportCreditors');
    }

    public function test_creditors_are_numbered_known_by_their_licence_and_copied_from_another_matter(): void
    {
        $mashreq = MatterCreditor::assign($this->matter, null, 'بنك المشرق', 'claims@mashreq.ae');
        $amal = MatterCreditor::assign($this->matter, null, 'مؤسسة الأمل', 'info@amal.ae');
        $this->assertSame([1, 2], [(int) $mashreq->number, (int) $amal->number]);
        $mashreq->update(['id_number' => 'CN-1001']);

        // The folder may carry the number: 001 before the name keeps the claims in order.
        Setting::set(ReceivedEmailFiling::FOLDER_SETTINGS[ReceivedEmailFiling::CREDITOR], '07 مطالبات الدائنين/{{creditor.number}} {{creditor}}', 'onedrive');
        Setting::clearCache();
        $this->assertSame('07 مطالبات الدائنين/001 بنك المشرق', MatterOneDriveFolders::receivedEmailsFolderFor($this->matter, now(), ReceivedEmailFiling::CREDITOR, 'claims@mashreq.ae'));

        // A second creditor with the same licence: refused on the Contacts tab.
        Livewire::test(MatterContacts::class, ['matter' => $this->matter])
            ->callTableAction('addCreditor', data: ['name' => 'المشرق مرة أخرى', 'id_number' => 'CN-1001', 'emails' => ['x@mashreq.ae']])
            ->assertHasTableActionErrors(['id_number']);
        $this->assertSame(2, $this->matter->creditors()->count());

        // A list with the licence: merged into it, not a second one — its own column kept with it.
        $this->matter->type->update(['creditor_columns' => ['ملف التنفيذ']]);
        app(MatterCreditors::class)->merge($this->matter->fresh(), 'Mashreq Bank PJSC', [], '300000', [], 'CN-1001', ['ملف التنفيذ' => '55/2026']);
        $this->assertSame(2, $this->matter->creditors()->count());
        $this->assertSame(['300000.00', '55/2026'], [$mashreq->fresh()->claim_amount, $mashreq->fresh()->customValue('ملف التنفيذ')]);

        // On another matter: known by its address, offered, and copied with its licence and agents.
        MatterCreditor::assign($this->matter, $mashreq->id, null, 'lawyer@hashmi.ae', true, 'مكتب الهاشمي');
        $other = Matter::factory()->create(['number' => '990', 'year' => '2026', 'type_id' => $this->matter->type_id]);
        $this->assertSame($mashreq->id, MatterCreditors::knownElsewhere($other, 'Claims@Mashreq.ae')?->id);
        $this->assertArrayHasKey($mashreq->id, MatterCreditors::elsewhere($other, 'CN-1001'));
        [$copied] = CreditorFields::save($other, ['copy_from' => $mashreq->id, 'name' => 'بنك المشرق', 'id_number' => 'CN-1001'], ['claims@mashreq.ae']);
        $this->assertSame($other->id, $copied->matter_id);
        $this->assertSame(['CN-1001', 1, null], [$copied->id_number, (int) $copied->number, $copied->claim_amount]);
        $this->assertEqualsCanonicalizing(['claims@mashreq.ae', 'lawyer@hashmi.ae'], $copied->addresses()->pluck('email')->all());
        $this->assertTrue($copied->addresses()->where('email', 'lawyer@hashmi.ae')->sole()->agent);
    }

    public function test_whom_a_creditor_copies_in_is_its_too_and_the_emails_from_no_creditor_are_filtered(): void
    {
        $creditor = MatterCreditor::assign($this->matter, null, 'بنك المشرق', 'claims@mashreq.ae');
        $email = $this->received('claims@mashreq.ae', 'وارد 1');
        // Its assistant copied in — and the plaintiff's address, which is a party's, not the creditor's.
        $email->update(['to' => [], 'cc' => ['assistant@mashreq.ae', 'info@mahad.ae']]);

        $this->assertSame(1, app(MatterCreditors::class)->learnCopied($email->fresh(), ReceivedEmailFiling::CREDITOR));
        $this->assertEqualsCanonicalizing(['claims@mashreq.ae', 'assistant@mashreq.ae'], $creditor->addresses()->pluck('email')->all());
        $this->assertSame(0, app(MatterCreditors::class)->learnCopied($email->fresh(), ReceivedEmailFiling::GENERAL));

        // The emails from no creditor yet, to assign.
        $stranger = $this->received('court@dubai.gov.ae', 'وارد 2');
        Livewire::test(EmailsRelationManager::class, ['ownerRecord' => $this->matter, 'pageClass' => ViewMatter::class])
            ->filterTable('no_creditor', true)
            ->assertCanSeeTableRecords([$stranger])
            ->assertCanNotSeeTableRecords([$email]);

        // When the first email came in from it, and its number: on the Contacts tab and in the sheet.
        $rows = collect((new MatterContactsExport($this->matter))->rows())->keyBy('name');
        $this->assertSame($email->at->toDateString(), $rows['بنك المشرق']['first_email']->toDateString());
        $this->assertSame(1, (int) $rows['بنك المشرق']['number']);
    }

    public function test_a_creditors_sheet_is_read_into_the_list_by_its_headings(): void
    {
        $this->matter->type->update(['creditor_columns' => ['ملف التنفيذ']]);
        $path = storage_path('app/temp/creditors-test.csv');
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        file_put_contents($path, "الاسم,البريد,الوكيل,الهاتف,قيمة المطالبة,رقم الرخصة,الآيبان,اسم البنك,ملف التنفيذ\nبنك المشرق,\"claims@mashreq.ae, lawyer@hashmi.ae\",مكتب الهاشمي,043334444,250000,CN-1001,ae07 0331 2345 6789 0123 456,Mashreq,55/2026\nمؤسسة الأمل,info@amal.ae,,,5000,,,,\n");

        $this->assertSame(2, app(MatterCreditors::class)->importSheet($this->matter->fresh(), $path));

        $mashreq = $this->matter->creditors()->where('name', 'بنك المشرق')->sole();
        $this->assertSame(['CN-1001', '250000.00', '55/2026', 1], [$mashreq->id_number, $mashreq->claim_amount, $mashreq->customValue('ملف التنفيذ'), (int) $mashreq->number]);
        // Its IBAN kept without the spaces it was written with.
        $this->assertSame(['AE070331234567890123456', 'Mashreq'], [$mashreq->iban, $mashreq->bank_name]);
        // The first address its own; the next the agent's, named as the sheet names it.
        $this->assertFalse($mashreq->addresses()->where('email', 'claims@mashreq.ae')->sole()->agent);
        $this->assertSame([true, 'مكتب الهاشمي'], [$mashreq->addresses()->where('email', 'lawyer@hashmi.ae')->sole()->agent, $mashreq->addresses()->where('email', 'lawyer@hashmi.ae')->sole()->agent_name]);

        // Read again: nothing twice.
        app(MatterCreditors::class)->importSheet($this->matter->fresh(), $path);
        $this->assertSame(2, $this->matter->creditors()->count());

        // The sheet for a bulk mail carries the number, the licence, the IBAN and bank and the own columns — each a placeholder.
        $this->assertSame([...MatterCreditors::BULK_MAIL_HEADINGS, 'number', 'id_number', 'iban', 'bank_name', 'ملف التنفيذ'], MatterCreditors::bulkMailHeadings($this->matter->fresh()));
        $this->assertSame(['بنك المشرق', 'claims@mashreq.ae, lawyer@hashmi.ae', 'مكتب الهاشمي', '043334444', '250000.00', '1', 'CN-1001', 'AE070331234567890123456', 'Mashreq', '55/2026'], MatterCreditors::bulkMailRows($this->matter->fresh())[0]);
        unlink($path);
    }

    public function test_an_email_sent_to_a_creditor_is_saved_with_its_claim_when_a_folder_is_set_for_it(): void
    {
        $mashreq = MatterCreditor::assign($this->matter, null, 'بنك المشرق', 'claims@mashreq.ae');
        MatterCreditor::assign($this->matter, $mashreq->id, null, 'lawyer@hashmi.ae', true, 'مكتب الهاشمي');
        Setting::set(MatterOneDriveFolders::SENT_EMAILS, 'المراسلات/صادر', 'onedrive');
        Setting::clearCache();

        // Nothing set for creditors: with the other sent emails.
        $this->assertSame('المراسلات/صادر', MatterOneDriveFolders::sentEmailsFolderFor($this->matter, now(), ['claims@mashreq.ae']));

        Setting::set(MatterOneDriveFolders::SENT_TO_CREDITORS, 'المطالبات/{{creditor.number}} {{creditor}}/صادر', 'onedrive');
        Setting::clearCache();

        // To the creditor, or its agent: with the claim. To anyone else: as before.
        $this->assertSame('المطالبات/001 بنك المشرق/صادر', MatterOneDriveFolders::sentEmailsFolderFor($this->matter, now(), ['claims@mashreq.ae']));
        $this->assertSame('المطالبات/001 بنك المشرق/صادر', MatterOneDriveFolders::sentEmailsFolderFor($this->matter, now(), ['info@mahad.ae', 'Lawyer@Hashmi.ae']));
        $this->assertSame('المراسلات/صادر', MatterOneDriveFolders::sentEmailsFolderFor($this->matter, now(), ['court@dubai.gov.ae']));

        // The type's own wins over the setting.
        $this->matter->type->update(['onedrive_sent_creditors' => 'مطالبات/{{creditor}}']);
        $this->assertSame('مطالبات/بنك المشرق', MatterOneDriveFolders::sentEmailsFolderFor($this->matter->fresh(), now(), ['claims@mashreq.ae']));
    }

    public function test_first_mails_go_in_one_folder_and_a_creditor_once_made_has_each_email_and_its_answers_in_a_numbered_folder(): void
    {
        Storage::fake('public');
        Setting::set(MatterOneDriveFolders::SENT_EMAILS, 'المراسلات/صادر', 'onedrive');
        Setting::set(MatterOneDriveFolders::FIRST_TO_CREDITORS, 'مطالبات الدائنين/000 الإخطارات', 'onedrive');
        Setting::set(ReceivedEmailFiling::FOLDER_SETTINGS[ReceivedEmailFiling::CREDITOR], 'مطالبات الدائنين/{{creditor.number}} {{creditor}}/{{serial.2}}', 'onedrive');
        Setting::set(MatterOneDriveFolders::SENT_TO_CREDITORS, 'مطالبات الدائنين/{{creditor.number}} {{creditor}}/{{serial.2}}', 'onedrive');
        Setting::clearCache();
        $archive = app(SentEmailArchive::class);
        $sent = fn (array $to, string $subject, ?int $parent = null, bool $first = false) => MatterEmail::create([
            'matter_id' => $this->matter->id, 'direction' => MatterEmail::SENT, 'sender_key' => 'iflas', 'subject' => $subject,
            'to' => $to, 'at' => now(), 'parent_id' => $parent, 'first_mail' => $first,
        ]);

        // The notice to whoever may be a creditor — no one on the list yet: in the first mails folder, by its name.
        $notice = $sent(['claims@mashreq.ae'], 'إخطار', first: true);
        $notice->noteKept($archive->keep($this->matter, '%PDF', 'بنك المشرق', 'إخطار', null, false, ['claims@mashreq.ae'], firstMail: true, record: $notice), 0);
        $this->assertContains('مطالبات الدائنين/000 الإخطارات/بنك المشرق.pdf', $this->oneDrive->files());

        // The bank answers (with the general emails until it is a creditor), I answer it, it answers again; then a new one.
        $answer = $this->received('Mashreq <claims@mashreq.ae>', 'وارد 1');
        $answer->update(['parent_id' => $notice->id]);
        $mine = $sent(['claims@mashreq.ae'], 'رد', $answer->id);
        $mine->update(['at' => now()->addSecond()]);
        $mine->noteKept($archive->keep($this->matter, '%PDF', 'بنك المشرق', 'رد', null, false, ['claims@mashreq.ae'], record: $mine), 0);
        $again = $this->received('claims@mashreq.ae', 'وارد 2');
        $again->update(['parent_id' => $mine->id, 'at' => now()->addMinute()]);
        $other = $this->received('claims@mashreq.ae', 'وارد 3');
        $other->update(['at' => now()->addMinutes(2)]);

        // Made a creditor: its emails and mine to it, each with what it answers — 01, 02; the notice stays.
        CreditorFields::save($this->matter, ['name' => 'بنك المشرق'], ['claims@mashreq.ae']);
        $files = $this->oneDrive->files();
        $this->assertContains('مطالبات الدائنين/000 الإخطارات/بنك المشرق.pdf', $files);
        $this->assertContains('مطالبات الدائنين/001 بنك المشرق/01/وارد 1.pdf', $files);
        $this->assertContains('مطالبات الدائنين/001 بنك المشرق/01/وارد 1.eml', $files);
        $this->assertContains('مطالبات الدائنين/001 بنك المشرق/01/وارد 2.pdf', $files);
        $this->assertContains('مطالبات الدائنين/001 بنك المشرق/02/وارد 3.pdf', $files);
        $this->assertSame('مطالبات الدائنين/001 بنك المشرق/01', MatterOneDriveFolders::emailFolder($mine->fresh(), $this->matter));
        $this->assertSame('مطالبات الدائنين/000 الإخطارات', MatterOneDriveFolders::emailFolder($notice->fresh(), $this->matter));

        // My answer to its last: beside it; a new email to it: the next number.
        $reply = $sent(['claims@mashreq.ae'], 'رد', $other->id);
        $this->assertSame('مطالبات الدائنين/001 بنك المشرق/02', $archive->keep($this->matter, '%PDF', 'بنك المشرق', 'رد', null, false, ['claims@mashreq.ae'], record: $reply)['folder']);
        $this->assertSame('مطالبات الدائنين/001 بنك المشرق/03', MatterOneDriveFolders::sentEmailsFolderFor($this->matter, now(), ['claims@mashreq.ae']));

        // Its "First email sent": the notice's day — and for someone a first mail went to who is no creditor.
        $court = $sent(['court@dubai.gov.ae'], 'إخطار', first: true);
        $court->update(['at' => now()->subDay()]);
        $rows = collect((new MatterContactsExport($this->matter))->rows());
        $this->assertSame($notice->at->toDateString(), $rows->firstWhere('name', 'بنك المشرق')['first_sent']->toDateString());
        $this->assertSame(now()->subDay()->toDateString(), $rows->firstWhere('emails', 'court@dubai.gov.ae')['first_sent']->toDateString());
    }

    public function test_a_sent_email_is_marked_as_a_first_mail_on_the_emails_tab_and_back(): void
    {
        Storage::fake('public');
        Setting::set(MatterOneDriveFolders::SENT_EMAILS, 'المراسلات/صادر', 'onedrive');
        Setting::set(MatterOneDriveFolders::FIRST_TO_CREDITORS, 'مطالبات الدائنين/000 الإخطارات', 'onedrive');
        Setting::set(CreditorFirstMails::NAME_SETTING, '{{name}} — {{date}}', 'onedrive');
        Setting::clearCache();
        Party::factory()->create(['name' => 'مؤسسة الأمل', 'email' => ['info@amal.ae']]);
        $this->oneDrive->put('المراسلات/صادر/إخطار الأمل.pdf');
        $email = MatterEmail::create([
            'matter_id' => $this->matter->id, 'direction' => MatterEmail::SENT, 'sender_key' => 'iflas', 'subject' => 'إخطار', 'to' => ['info@amal.ae'], 'at' => now(),
            'files' => [['name' => 'إخطار الأمل.pdf', 'path' => null]], 'onedrive' => ['pdf' => true, 'folder' => 'المراسلات/صادر'],
        ]);

        Livewire::test(EmailsRelationManager::class, ['ownerRecord' => $this->matter, 'pageClass' => ViewMatter::class])
            ->callAction(TestAction::make('firstMail')->table($email))
            ->assertHasNoErrors();

        $name = 'مؤسسة الأمل — '.now()->format('Y-m-d');
        $this->assertTrue($email->fresh()->first_mail);
        $this->assertSame(['مطالبات الدائنين/000 الإخطارات/'.$name.'.pdf'], $this->oneDrive->files());
        $this->assertSame($name.'.pdf', $email->fresh()->files[0]['name']);

        // Not one after all: back with the other sent emails.
        Livewire::test(EmailsRelationManager::class, ['ownerRecord' => $this->matter, 'pageClass' => ViewMatter::class])
            ->callAction(TestAction::make('firstMail')->table($email->fresh()));
        $this->assertFalse($email->fresh()->first_mail);
        $this->assertStringStartsWith('المراسلات/صادر/', $this->oneDrive->files()[0]);
    }

    public function test_a_party_is_made_a_creditor_too_from_its_row_or_with_others_at_once(): void
    {
        $plaintiff = 'p:'.MatterParty::query()->where('matter_id', $this->matter->id)->where('type', 'plaintiff')->value('party_id');
        MatterParty::create(['matter_id' => $this->matter->id, 'party_id' => Party::factory()->create(['name' => 'شركة النور', 'email' => ['noor@noor.ae'], 'phone' => ['0501111111']])->id, 'role' => 'party', 'type' => 'defendant']);
        $defendant = 'p:'.MatterParty::query()->where('matter_id', $this->matter->id)->where('type', 'defendant')->value('party_id');
        // Each side's lawyer on the matter, and what the plaintiff's wrote before.
        $lawyer = fn (string $side, string $name, string $email): string => 'p:'.MatterParty::create(['matter_id' => $this->matter->id, 'party_id' => Party::factory()->create(['name' => $name, 'email' => [$email]])->id, 'role' => 'representative', 'parent_id' => MatterParty::query()->where('matter_id', $this->matter->id)->where('type', $side)->value('id')])->party_id;
        $lawyer('plaintiff', 'مكتب الهاشمي', 'lawyer@hashmi.ae');
        $noorLawyer = $lawyer('defendant', 'مكتب النور للمحاماة', 'law@noor.ae');
        $fromLawyer = $this->received('lawyer@hashmi.ae', 'وارد 0');
        $this->received('court@dubai.gov.ae', 'وارد 1');

        // From its row: the plaintiff is a creditor too — both capacities on the one row, its emails filed as a creditor's.
        Livewire::test(MatterContacts::class, ['matter' => $this->matter])
            ->callTableAction('makeCreditor', $plaintiff, ['name' => 'شركة المهاد', 'agent' => false])
            ->assertHasNoTableActionErrors();
        $mahad = $this->matter->creditors()->where('name', 'شركة المهاد')->sole();
        // Its lawyer on the matter: its agent at once, by name and address, and the lawyer's email moved to its folder.
        $this->assertSame(['info@mahad.ae' => [false, null], 'lawyer@hashmi.ae' => [true, 'مكتب الهاشمي']], $mahad->addresses()->orderBy('agent')->get()->mapWithKeys(fn ($address): array => [$address->email => [(bool) $address->agent, $address->agent_name]])->all());
        $this->assertSame(ReceivedEmailFiling::CREDITOR, ReceivedEmailFiling::side($this->matter, 'lawyer@hashmi.ae'));
        $this->assertSame($mahad->id, MatterEmail::query()->whereKey($fromLawyer->id)->value('matter_creditor_id') ?? $mahad->id);
        $rows = collect((new MatterContactsExport($this->matter))->rows())->keyBy('key');
        $this->assertSame($mahad->id, $rows[$plaintiff]['creditor_id']);
        $this->assertStringContainsString('الدائن', $rows[$plaintiff]['capacity']);
        $this->assertStringContainsString(Type::sideLabel($this->matter->type, 'plaintiff'), $rows[$plaintiff]['capacity']);
        $this->assertSame(ReceivedEmailFiling::CREDITOR, ReceivedEmailFiling::side($this->matter, 'info@mahad.ae'));
        // Its row and its lawyer's carry the creditor: the lawyer's as its agent.
        $this->assertSame([false, true], $rows->filter(fn (array $row): bool => $row['creditor_id'] === $mahad->id)->pluck('agent')->sort()->values()->all());
        // One Edit on its row: the creditor's.
        Livewire::test(MatterContacts::class, ['matter' => $this->matter])
            ->assertTableActionVisible('editCreditor', $plaintiff)
            ->assertTableActionHidden('edit', $plaintiff)
            ->assertTableActionVisible('edit', $defendant);

        // Many at once: the defendant and someone who wrote in — the plaintiff, a creditor already, left as it is;
        // the defendant's lawyer, ticked along, linked as its agent and not made a creditor of its own.
        Livewire::test(MatterContacts::class, ['matter' => $this->matter])
            ->callTableBulkAction('makeCreditors', [$plaintiff, $defendant, $noorLawyer, 'e:court@dubai.gov.ae'])
            ->assertHasNoTableBulkActionErrors()
            ->assertNotified(trans_choice('No creditor made.|:count creditor made.|:count creditors made.', 2, ['count' => 2]));
        $this->assertSame(3, $this->matter->creditors()->count());
        $noor = $this->matter->creditors()->where('name', 'شركة النور')->sole();
        $this->assertSame([['0501111111'], ['noor@noor.ae', 'law@noor.ae'], [false, true]], [$noor->phones, $noor->addresses()->orderBy('agent')->pluck('email')->all(), $noor->addresses()->orderBy('agent')->pluck('agent')->map(fn ($agent): bool => (bool) $agent)->all()]);
        $this->assertSame([1, 2, 3], $this->matter->creditors()->orderBy('number')->pluck('number')->map(fn ($n): int => (int) $n)->all());

        // Edited from the party's row: every column of a creditor, its number among them.
        Livewire::test(MatterContacts::class, ['matter' => $this->matter])
            ->callTableAction('editCreditor', $plaintiff, ['name' => 'شركة المهاد', 'number' => 9, 'id_number' => 'CN-9', 'claim_amount' => '1000', 'emails' => ['info@mahad.ae'], 'phone' => []])
            ->assertHasNoTableActionErrors();
        $this->assertSame([9, 'CN-9', '1000.00'], [(int) $mahad->fresh()->number, $mahad->fresh()->id_number, $mahad->fresh()->claim_amount]);
    }

    public function test_a_creditors_iban_and_bank_are_kept_and_the_first_email_sent_to_each_is_its_own(): void
    {
        $mashreq = MatterCreditor::assign($this->matter, null, 'بنك المشرق', 'claims@mashreq.ae');
        MatterCreditor::assign($this->matter, (int) $mashreq->id, null, 'lawyer@hashmi.ae', true, 'مكتب الهاشمي');
        $amal = MatterCreditor::assign($this->matter, null, 'مؤسسة الأمل', 'info@amal.ae');
        $noor = MatterCreditor::assign($this->matter, null, 'شركة النور', 'noor@noor.ae');

        // Its IBAN and bank on the Contacts tab: any country's, typed with spaces; a wrong one refused.
        Livewire::test(MatterContacts::class, ['matter' => $this->matter])
            ->callTableAction('editCreditor', 'c:'.$mashreq->id, ['name' => 'بنك المشرق', 'iban' => 'gb29 nwbk 6016 1331 9268 19', 'bank_name' => 'Barclays Bank', 'emails' => ['claims@mashreq.ae'], 'phone' => []])
            ->assertHasNoTableActionErrors()
            ->callTableAction('editCreditor', 'c:'.$amal->id, ['name' => 'مؤسسة الأمل', 'iban' => '12345', 'emails' => ['info@amal.ae'], 'phone' => []])
            ->assertHasTableActionErrors(['iban' => 'regex']);
        $this->assertSame(['GB29NWBK60161331926819', 'Barclays Bank'], [$mashreq->fresh()->iban, $mashreq->fresh()->bank_name]);

        // Written to one by one: the bank's agent on the 3rd (copied in), the bank itself on the 5th; Amal by the
        // bulk mail on the 4th; Noor not yet.
        $sent = fn (array $to, array $cc, string $at) => MatterEmail::create(['matter_id' => $this->matter->id, 'direction' => MatterEmail::SENT, 'sender_key' => 'office', 'subject' => 'إخطار', 'to' => $to, 'cc' => $cc, 'at' => Carbon::parse($at)]);
        $sent(['claims@mashreq.ae'], [], '2026-03-05 10:00');
        $sent(['court@dubai.gov.ae'], ['lawyer@hashmi.ae'], '2026-03-03 09:00');
        $campaign = $this->campaign();
        $campaign->recipients()->create(['name' => 'مؤسسة الأمل', 'email' => ['info@amal.ae'], 'status' => 'sent', 'sent_at' => Carbon::parse('2026-03-04 08:00')]);
        $campaign->recipients()->create(['name' => 'شركة النور', 'email' => ['noor@noor.ae'], 'status' => 'pending']);

        $this->assertSame([$mashreq->id => '2026-03-03', $amal->id => '2026-03-04'], array_map(fn (Carbon $at): string => $at->toDateString(), MatterCreditors::firstSentAt($this->matter)));
        $rows = collect((new MatterContactsExport($this->matter))->rows())->keyBy('name');
        $this->assertSame(['2026-03-03', 'GB29NWBK60161331926819', 'Barclays Bank'], [$rows['بنك المشرق']['first_sent']->toDateString(), $rows['بنك المشرق']['iban'], $rows['بنك المشرق']['bank_name']]);
        $this->assertNull($rows['شركة النور']['first_sent']);

        // Copied to the same creditor on another matter with its bank account.
        $other = Matter::factory()->create(['number' => '990', 'year' => '2026', 'type_id' => $this->matter->type_id]);
        $copied = app(MatterCreditors::class)->copy($other, $mashreq->fresh());
        $this->assertSame(['GB29NWBK60161331926819', 'Barclays Bank'], [$copied->iban, $copied->bank_name]);
    }

    public function test_a_creditor_saved_with_no_number_is_given_the_next_and_those_from_before_the_numbers_theirs_first(): void
    {
        // As a server had them before the numbers: three creditors with none, and one numbered since.
        $mashreq = MatterCreditor::assign($this->matter, null, 'بنك المشرق', 'claims@mashreq.ae');
        $amal = MatterCreditor::assign($this->matter, null, 'مؤسسة الأمل', 'info@amal.ae');
        $noor = MatterCreditor::assign($this->matter, null, 'شركة النور', 'noor@noor.ae');
        DB::table('matter_creditors')->whereIn('id', [$mashreq->id, $amal->id, $noor->id])->update(['number' => null]);
        $later = MatterCreditor::assign($this->matter, null, 'شركة لاحقة', 'later@later.ae');
        $this->assertSame([1, 2, 3, 4], [(int) $mashreq->fresh()->number, (int) $amal->fresh()->number, (int) $noor->fresh()->number, (int) $later->fresh()->number]);

        // Added from the Contacts tab with "No." empty: numbered after them.
        Livewire::test(MatterContacts::class, ['matter' => $this->matter])
            ->callTableAction('addCreditor', data: ['name' => 'شركة المهاد', 'number' => null, 'emails' => ['info@mahad.ae']])
            ->assertHasNoTableActionErrors();
        $this->assertSame(5, (int) $this->matter->creditors()->where('name', 'شركة المهاد')->sole()->number);

        // Edited with "No." emptied: given the next, not left empty.
        DB::table('matter_creditors')->where('id', $noor->id)->update(['number' => null]);
        Livewire::test(MatterContacts::class, ['matter' => $this->matter])
            ->callTableAction('editCreditor', 'c:'.$noor->id, ['name' => 'شركة النور', 'number' => '', 'emails' => ['noor@noor.ae'], 'phone' => []])
            ->assertHasNoTableActionErrors();
        $this->assertSame(6, (int) $noor->fresh()->number);

        // The upgrade numbers those a server has with none, matter by matter.
        $other = Matter::factory()->create(['number' => '990', 'year' => '2026', 'type_id' => $this->matter->type_id]);
        $a = MatterCreditor::assign($other, null, 'أ', 'a@a.ae');
        $b = MatterCreditor::assign($other, null, 'ب', 'b@b.ae');
        DB::table('matter_creditors')->whereIn('id', [$mashreq->id, $a->id, $b->id])->update(['number' => null]);
        $migration = 'database/migrations/2020_01_01_000232_number_the_creditors_without_a_number.php';
        Artisan::call('migrate:rollback', ['--path' => $migration]);
        Artisan::call('migrate', ['--path' => $migration]);
        $this->assertSame(7, (int) $mashreq->fresh()->number);
        $this->assertSame([1, 2], [(int) $a->fresh()->number, (int) $b->fresh()->number]);
    }

    public function test_someone_who_came_by_email_is_merged_into_or_made_an_agent_of_someone_on_the_matter_and_their_emails_follow(): void
    {
        Setting::set(ReceivedEmailFiling::FOLDER_SETTINGS[ReceivedEmailFiling::PLAINTIFF], '05 المدعي', 'onedrive');
        Setting::clearCache();
        $mashreq = MatterCreditor::assign($this->matter, null, 'بنك المشرق', 'info@mashreq.ae');
        $plaintiffId = (int) MatterParty::query()->where('matter_id', $this->matter->id)->where('type', 'plaintiff')->value('party_id');
        foreach (['claims@mashreq.ae', 'lawyer@hashmi.ae', 'manager@mahad.ae', 'advocate@law.ae'] as $i => $address) {
            $this->received($address, 'وارد '.$i);
        }
        $tab = fn () => Livewire::test(MatterContacts::class, ['matter' => $this->matter]);

        // Whom to choose: the matter's parties and creditors, and a new creditor.
        $targets = ContactLinker::targets(collect((new MatterContactsExport($this->matter))->rows())->keyBy('key')->all(), ContactLinker::AGENT, true);
        $this->assertSame(['new', 'c:'.$mashreq->id], array_keys($targets[__('Creditors')]));
        $this->assertArrayHasKey('p:'.$plaintiffId, $targets[__('Parties')]);

        // Merged into the creditor: its own address, its email in its folder — the party ticked along left as it is.
        $tab()->callTableBulkAction('mergeInto', ['e:claims@mashreq.ae', 'p:'.$plaintiffId], ['target' => 'c:'.$mashreq->id])
            ->assertHasNoTableBulkActionErrors();
        $this->assertSame(['claims@mashreq.ae', 'info@mashreq.ae'], $mashreq->addresses()->where('agent', false)->orderBy('email')->pluck('email')->all());
        $this->assertContains('07 مطالبات الدائنين/بنك المشرق/وارد 0.pdf', $this->oneDrive->files());

        // The agent of a new creditor, named here: by their own name, their email in the new creditor's folder.
        $tab()->callTableBulkAction('agentOf', ['e:lawyer@hashmi.ae'], ['target' => 'new', 'creditor_name' => 'بنك دبي'])
            ->assertHasNoTableBulkActionErrors();
        $dubai = $this->matter->creditors()->where('name', 'بنك دبي')->sole();
        $this->assertTrue((bool) $dubai->addresses()->where('email', 'lawyer@hashmi.ae')->sole()->agent);
        $this->assertContains('07 مطالبات الدائنين/بنك دبي/وارد 1.pdf', $this->oneDrive->files());

        // Merged into the plaintiff: the party's own address now, its email in the plaintiff's folder.
        $tab()->callTableBulkAction('mergeInto', ['e:manager@mahad.ae'], ['target' => 'p:'.$plaintiffId])
            ->assertHasNoTableBulkActionErrors();
        $this->assertSame(['info@mahad.ae', 'manager@mahad.ae'], Party::find($plaintiffId)->email);
        $this->assertContains('05 المدعي/وارد 2.pdf', $this->oneDrive->files());

        // The plaintiff's agent: its representative on the matter, of a party of their own; their email on its side.
        $tab()->callTableBulkAction('agentOf', ['e:advocate@law.ae'], ['target' => 'p:'.$plaintiffId])
            ->assertHasNoTableBulkActionErrors();
        $advocate = Party::query()->where('email', 'like', '%advocate@law.ae%')->sole();
        $row = MatterParty::query()->where('matter_id', $this->matter->id)->where('party_id', $advocate->id)->sole();
        $this->assertSame(['representative', MatterParty::query()->where('matter_id', $this->matter->id)->where('party_id', $plaintiffId)->value('id')], [$row->role, $row->parent_id]);
        $this->assertSame(ReceivedEmailFiling::PLAINTIFF, ReceivedEmailFiling::side($this->matter, 'advocate@law.ae'));
        $this->assertContains('05 المدعي/وارد 3.pdf', $this->oneDrive->files());
        $this->assertNotContains('04 المراسلات/وارد 3.pdf', $this->oneDrive->files());
    }
}
