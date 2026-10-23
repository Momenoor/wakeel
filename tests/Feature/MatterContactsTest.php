<?php

namespace Tests\Feature;

use App\Filament\Mms\Resources\BulkMailCampaigns\BulkMailCampaignResource;
use App\Filament\Mms\Resources\BulkMailCampaigns\Pages\ViewBulkMailCampaign;
use App\Filament\Mms\Resources\BulkMailCampaigns\RelationManagers\RecipientsRelationManager;
use App\Filament\Mms\Resources\Matters\Pages\ViewMatter;
use App\Filament\Mms\Resources\Matters\RelationManagers\BulkMailRelationManager;
use App\Livewire\MatterContacts;
use App\Models\BulkMailCampaign;
use App\Models\Matter;
use App\Models\MatterContact;
use App\Models\MatterEmail;
use App\Models\MatterParty;
use App\Models\Party;
use App\Models\User;
use App\Services\MMS\MatterContactsExport;
use App\Services\MMS\MatterReplyCollector;
use App\Services\MMS\SentFolder;
use App\Support\MatterEvents;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Mockery;
use Spatie\Permission\Models\Role;
use Tests\Support\MailSenders;
use Tests\TestCase;

/**
 * Whom a matter's parties bring in — the others in To or CC of their reply
 * — kept as theirs on that matter only, copied in when they are emailed on
 * it again, and with everyone else the matter dealt with in its contacts
 * export.
 */
class MatterContactsTest extends TestCase
{
    use RefreshDatabase;

    private Matter $matter;

    private Party $mmmm;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->withoutDefer();
        Http::fake();
        MailSenders::add('iflas', ['username' => 'iflas@jpa.ae', 'address' => 'iflas@jpa.ae', 'name' => 'JPA', 'password' => 'x', 'host' => 'mail.test', 'port' => 587, 'encryption' => 'tls']);
        $this->app->instance(SentFolder::class, Mockery::spy(SentFolder::class));
        Gate::before(fn () => true);
        Filament::setCurrentPanel(Filament::getPanel('mms'));

        Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        $this->user = User::factory()->create(['email' => 'momen@office.ae'])->assignRole('super-admin');
        $this->actingAs($this->user);

        $this->matter = Matter::factory()->create(['number' => '986', 'year' => '2026']);
        $this->mmmm = Party::factory()->create(['name' => 'MMMM', 'email' => ['mmm@mmm.ae'], 'phone' => ['0501112222']]);
        MatterParty::create(['matter_id' => $this->matter->id, 'party_id' => $this->mmmm->id, 'role' => 'party', 'type' => 'defendant']);
    }

    /** MMMM replies to an email of the matter, with others in To and CC. */
    private function reply(array $to, array $cc): void
    {
        $sent = MatterEmail::create(['matter_id' => $this->matter->id, 'direction' => MatterEmail::SENT, 'sender_key' => 'iflas', 'message_id' => 's1@jpa.ae',
            'subject' => 'طلب مستندات', 'to' => ['mmm@mmm.ae'], 'at' => now()->subDay(), 'user_id' => $this->user->id]);

        app(MatterReplyCollector::class)->handle([[
            'message_id' => '<r1@mmm.ae>', 'references' => ['<s1@jpa.ae>'], 'subject' => 'RE: طلب مستندات', 'from' => 'mmm@mmm.ae', 'from_name' => 'MMMM',
            'to' => $to, 'cc' => $cc, 'at' => now()->toDateTimeString(), 'body' => '<p>مرفق.</p>', 'attachments' => [],
        ]], collect([$sent]), 'iflas');
    }

    public function test_whom_a_party_copies_in_on_a_reply_is_theirs_on_this_matter_only(): void
    {
        $other = Party::factory()->create(['email' => ['known@party.ae']]);

        // Besides xxx and ccc: our own mailbox, a colleague, a user, someone already a party.
        $this->reply(['iflas@jpa.ae', 'XXX@xxx.ae'], ['ccc@ccc.ae', 'colleague@jpa.ae', 'momen@office.ae', 'known@party.ae']);

        $contacts = MatterContact::all();
        $this->assertEqualsCanonicalizing(['xxx@xxx.ae', 'ccc@ccc.ae'], $contacts->pluck('email')->all());
        $this->assertSame([$this->mmmm->id], $contacts->pluck('party_id')->unique()->values()->all());
        $this->assertSame('mmm@mmm.ae', $contacts->first()->owner_email);

        // Not on the party itself.
        $this->assertSame(['mmm@mmm.ae'], $this->mmmm->refresh()->email);
        $this->assertSame(['known@party.ae'], $other->refresh()->email);
    }

    public function test_they_are_copied_in_on_the_email_to_them_only(): void
    {
        $this->reply(['iflas@jpa.ae'], ['xxx@xxx.ae', 'ccc@ccc.ae']);

        $this->assertSame(['xxx@xxx.ae', 'ccc@ccc.ae'], MatterContact::copiedFor($this->matter->id, ['mmm@mmm.ae']));
        // By the party too, whatever address it's emailed at.
        $this->assertSame(['xxx@xxx.ae', 'ccc@ccc.ae'], MatterContact::copiedFor($this->matter->id, ['other@mmm.ae'], [$this->mmmm->id]));
        // Not on an email to someone else, nor on another matter.
        $this->assertSame([], MatterContact::copiedFor($this->matter->id, ['someone@else.ae']));
        $this->assertSame([], MatterContact::copiedFor(Matter::factory()->create()->id, ['mmm@mmm.ae']));
        // Unticked on the send form: not this time.
        $this->assertSame(['ccc@ccc.ae'], MatterContact::copiedFor($this->matter->id, ['mmm@mmm.ae'], [], ['ccc@ccc.ae']));

        // A bulk email of the matter to them copies them in.
        $campaign = BulkMailCampaign::create(['name' => 'Notice', 'subject' => 'إخطار', 'body' => '<p>…</p>', 'from_sender_key' => 'iflas', 'created_by' => $this->user->id, 'status' => 'draft', 'matter_id' => $this->matter->id]);
        $recipient = $campaign->recipients()->create(['email' => ['mmm@mmm.ae'], 'name' => 'MMMM', 'cc_emails' => ['boss@mmm.ae'], 'status' => 'pending']);
        $this->assertSame(['boss@mmm.ae', 'xxx@xxx.ae', 'ccc@ccc.ae'], $recipient->cc_emails);
    }

    public function test_the_export_lists_everyone_the_matter_dealt_with_once_each(): void
    {
        $lawyer = Party::factory()->create(['name' => 'مكتب البنا', 'email' => ['office@albanna.ae']]);
        $row = MatterParty::query()->where('party_id', $this->mmmm->id)->sole();
        MatterParty::create(['matter_id' => $this->matter->id, 'party_id' => $lawyer->id, 'role' => 'representative', 'parent_id' => $row->id]);
        // An expert of the office: not a contact.
        MatterParty::create(['matter_id' => $this->matter->id, 'party_id' => Party::factory()->certifiedExpert()->create(['name' => 'الخبير'])->id, 'role' => 'expert', 'type' => 'certified']);

        // A creditor by bulk mail; MMMM again by bulk mail; someone emailed only.
        $campaign = BulkMailCampaign::create(['name' => 'الدائنون', 'subject' => 'إخطار', 'body' => '<p>…</p>', 'from_sender_key' => 'iflas', 'created_by' => $this->user->id, 'status' => 'completed', 'matter_id' => $this->matter->id]);
        $campaign->recipients()->create(['email' => ['creditor@bank.ae'], 'name' => 'بنك الدائن', 'status' => 'sent']);
        $campaign->recipients()->create(['email' => ['MMM@mmm.ae'], 'name' => 'MMMM', 'status' => 'sent']);
        $this->reply(['iflas@jpa.ae', 'clerk@court.ae'], ['xxx@xxx.ae']);

        $rows = collect((new MatterContactsExport($this->matter))->rows())->keyBy('name');

        $this->assertEqualsCanonicalizing(['MMMM', 'مكتب البنا', 'بنك الدائن'], $rows->keys()->all());
        $this->assertSame('mmm@mmm.ae', $rows['MMMM']['emails']);
        $this->assertSame('0501112222', $rows['MMMM']['phones']);
        // Whom MMMM brought in, on their line.
        $this->assertSame('clerk@court.ae, xxx@xxx.ae', $rows['MMMM']['theirs']);
        $this->assertStringContainsString(__('Bulk mail: :name', ['name' => 'الدائنون']), $rows['MMMM']['source']);
        $this->assertSame('MMMM', $rows['مكتب البنا']['for']);
        $this->assertSame('creditor@bank.ae', $rows['بنك الدائن']['emails']);

        // From the matter page.
        Livewire::test(ViewMatter::class, ['record' => $this->matter->getRouteKey()])
            ->callAction('exportContacts')
            ->assertFileDownloaded();
    }

    public function test_the_contacts_tab_lists_them_and_their_other_emails_are_corrected_there(): void
    {
        $this->reply(['iflas@jpa.ae'], ['xxx@xxx.ae', 'ccc@ccc.ae']);

        $tab = Livewire::test(MatterContacts::class, ['matter' => $this->matter])
            ->assertSee('MMMM')
            ->assertSee('xxx@xxx.ae')
            ->callTableAction('otherEmails', 'p:'.$this->mmmm->id, ['emails' => ['ccc@ccc.ae', 'new@mmm-legal.ae']])
            ->assertHasNoTableActionErrors();

        $this->assertEqualsCanonicalizing(['ccc@ccc.ae', 'new@mmm-legal.ae'], $this->matter->contacts()->pluck('email')->all());
        $this->assertEqualsCanonicalizing(['ccc@ccc.ae', 'new@mmm-legal.ae'], MatterContact::copiedFor($this->matter->id, ['mmm@mmm.ae']));
        $tab->callTableAction('export')->assertFileDownloaded();
    }

    public function test_a_partys_name_phones_and_emails_are_edited_there_on_the_party(): void
    {
        Party::factory()->create(['name' => 'Taken']);
        // Someone only emailed: no party to change.
        MatterEmail::create(['matter_id' => $this->matter->id, 'direction' => MatterEmail::SENT, 'sender_key' => 'iflas', 'message_id' => 's2@jpa.ae',
            'subject' => 'إخطار', 'to' => ['other@other.ae'], 'at' => now(), 'user_id' => $this->user->id]);

        $tab = Livewire::test(MatterContacts::class, ['matter' => $this->matter])
            ->assertTableActionHidden('edit', 'e:other@other.ae')
            ->assertTableActionVisible('edit', 'p:'.$this->mmmm->id)
            ->mountTableAction('edit', 'p:'.$this->mmmm->id)
            ->assertTableActionDataSet(['name' => 'MMMM', 'phone' => ['0501112222'], 'email' => ['mmm@mmm.ae']])
            // Another party's name; not a UAE number.
            ->setTableActionData(['name' => 'Taken', 'phone' => ['123']])
            ->callMountedTableAction()
            ->assertHasTableActionErrors(['name', 'phone.0']);

        $tab->setTableActionData(['name' => 'MMMM Legal', 'phone' => ['0501112222', '0559998888'], 'email' => ['info@mmm.ae']])
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors()
            // Drawn again as the matter's page tells it.
            ->dispatch(MatterEvents::CHANGED)
            ->assertSee('MMMM Legal')
            ->assertSee('info@mmm.ae');

        $this->assertSame(['MMMM Legal', ['0501112222', '0559998888'], ['info@mmm.ae']], [$this->mmmm->fresh()->name, $this->mmmm->fresh()->phone, $this->mmmm->fresh()->email]);
    }

    public function test_the_bulk_mail_tab_lists_the_matters_campaigns_and_starts_a_new_one_with_it(): void
    {
        BulkMailCampaign::create(['name' => 'الدائنون', 'subject' => 'إخطار', 'body' => '<p>…</p>', 'from_sender_key' => 'iflas', 'created_by' => $this->user->id, 'status' => 'draft', 'matter_id' => $this->matter->id]);
        BulkMailCampaign::create(['name' => 'Another', 'subject' => 'x', 'body' => '<p>…</p>', 'from_sender_key' => 'iflas', 'created_by' => $this->user->id, 'status' => 'draft']);

        Livewire::test(BulkMailRelationManager::class, ['ownerRecord' => $this->matter, 'pageClass' => ViewMatter::class])
            ->assertSee('الدائنون')
            ->assertDontSee('Another')
            ->assertActionHasUrl(TestAction::make('new')->table(), BulkMailCampaignResource::getUrl('create', ['matter' => $this->matter->id]));

        $this->get(BulkMailCampaignResource::getUrl('create', ['matter' => $this->matter->id]))->assertOk();
    }

    public function test_a_campaigns_recipient_is_edited_or_deleted_on_its_page(): void
    {
        $campaign = BulkMailCampaign::create(['name' => 'Notice', 'subject' => 'إخطار', 'body' => '<p>…</p>', 'from_sender_key' => 'iflas', 'created_by' => $this->user->id, 'status' => 'draft', 'total_recipients' => 2]);
        $wrong = $campaign->recipients()->create(['email' => ['wrong@x.ae'], 'name' => 'Wrong', 'status' => 'pending']);
        $gone = $campaign->recipients()->create(['email' => ['gone@x.ae'], 'name' => 'Gone', 'status' => 'pending']);

        Livewire::test(RecipientsRelationManager::class, ['ownerRecord' => $campaign, 'pageClass' => ViewBulkMailCampaign::class])
            ->callTableAction('edit', $wrong, ['email' => ['right@x.ae'], 'name' => 'Right'])
            ->assertHasNoTableActionErrors()
            ->callTableAction('delete', $gone);

        $this->assertSame(['right@x.ae'], $wrong->refresh()->email);
        $this->assertModelMissing($gone);
        $this->assertSame(1, $campaign->refresh()->total_recipients);
    }
}
