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
use App\Services\MMS\MatterContactsExport;
use App\Services\MMS\MatterCreditors;
use App\Services\MMS\MatterOneDriveFolders;
use App\Services\MMS\OneDriveClient;
use App\Services\MMS\ReceivedEmailFiling;
use Filament\Actions\Imports\Models\Import;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
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
        $this->assertSame([['بنك المشرق', 'claims@mashreq.ae, lawyer@hashmi.ae, hashmi@gmail.com', 'مكتب الهاشمي', '043334444', '250000.00', '1', '']], MatterCreditors::bulkMailRows($this->matter));
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
        file_put_contents($path, "الاسم,البريد,الوكيل,الهاتف,قيمة المطالبة,رقم الرخصة,ملف التنفيذ\nبنك المشرق,\"claims@mashreq.ae, lawyer@hashmi.ae\",مكتب الهاشمي,043334444,250000,CN-1001,55/2026\nمؤسسة الأمل,info@amal.ae,,,5000,,\n");

        $this->assertSame(2, app(MatterCreditors::class)->importSheet($this->matter->fresh(), $path));

        $mashreq = $this->matter->creditors()->where('name', 'بنك المشرق')->sole();
        $this->assertSame(['CN-1001', '250000.00', '55/2026', 1], [$mashreq->id_number, $mashreq->claim_amount, $mashreq->customValue('ملف التنفيذ'), (int) $mashreq->number]);
        // The first address its own; the next the agent's, named as the sheet names it.
        $this->assertFalse($mashreq->addresses()->where('email', 'claims@mashreq.ae')->sole()->agent);
        $this->assertSame([true, 'مكتب الهاشمي'], [$mashreq->addresses()->where('email', 'lawyer@hashmi.ae')->sole()->agent, $mashreq->addresses()->where('email', 'lawyer@hashmi.ae')->sole()->agent_name]);

        // Read again: nothing twice.
        app(MatterCreditors::class)->importSheet($this->matter->fresh(), $path);
        $this->assertSame(2, $this->matter->creditors()->count());

        // The sheet for a bulk mail carries the number, the licence and the own columns — each a placeholder.
        $this->assertSame([...MatterCreditors::BULK_MAIL_HEADINGS, 'number', 'id_number', 'ملف التنفيذ'], MatterCreditors::bulkMailHeadings($this->matter->fresh()));
        $this->assertSame(['بنك المشرق', 'claims@mashreq.ae, lawyer@hashmi.ae', 'مكتب الهاشمي', '043334444', '250000.00', '1', 'CN-1001', '55/2026'], MatterCreditors::bulkMailRows($this->matter->fresh())[0]);
        unlink($path);
    }
}
