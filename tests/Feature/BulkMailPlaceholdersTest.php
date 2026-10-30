<?php

namespace Tests\Feature;

use App\Enums\BulkMailCampaignStatus;
use App\Filament\Mms\Imports\BulkMailRecipientImporter;
use App\Filament\Mms\Resources\BulkMailCampaigns\Pages\CreateBulkMailCampaign;
use App\Models\BulkMailCampaign;
use App\Models\BulkMailRecipient;
use App\Models\Court;
use App\Models\Matter;
use App\Models\MatterParty;
use App\Models\Party;
use App\Models\User;
use App\Services\MMS\BulkMailPlaceholders;
use Filament\Actions\Imports\Models\Import;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Support\MailSenders;
use Tests\TestCase;

class BulkMailPlaceholdersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        MailSenders::add('test', [
            'username' => 'sender@example.com', 'address' => 'sender@example.com', 'name' => 'Test Sender',
            'password' => 'secret', 'host' => 'mail.example.com', 'port' => 587, 'encryption' => 'tls',
        ]);
    }

    private function campaign(?Matter $matter, string $subject, string $body): BulkMailCampaign
    {
        return BulkMailCampaign::create([
            'name' => 'Notice',
            'subject' => $subject,
            'body' => $body,
            'from_sender_key' => 'test',
            'matter_id' => $matter?->id,
            'daily_send_limit' => 60,
            'status' => BulkMailCampaignStatus::Draft,
            'created_by' => User::factory()->create()->id,
        ]);
    }

    private function recipient(BulkMailCampaign $campaign, array $placeholders = []): BulkMailRecipient
    {
        return BulkMailRecipient::create([
            'campaign_id' => $campaign->id,
            'email' => ['creditor@example.com'],
            'name' => 'Emirates Trading LLC',
            'placeholders' => $placeholders ?: null,
        ]);
    }

    private function matter(): Matter
    {
        $matter = Matter::factory()->create([
            'number' => '125',
            'year' => '2025',
            'court_id' => Court::factory()->create(['name' => 'Dubai Courts'])->id,
            'next_session_date' => '2026-10-05 10:00:00',
            'custom_fields' => ['Trustee Name' => 'Ahmed Ali'],
        ]);

        $party = fn (string $name, string $role, string $type) => MatterParty::create([
            'matter_id' => $matter->id,
            'party_id' => Party::factory()->create(['name' => $name])->id,
            'role' => $role,
            'type' => $type,
        ]);

        $party('Plaintiff Co', 'party', 'plaintiff');
        $party('Defendant One', 'party', 'defendant');
        $party('Defendant Two', 'party', 'defendant');
        $party('Expert Reda', 'expert', 'certified');
        $party('Assistant Sara', 'expert', 'assistant');

        return $matter;
    }

    public function test_a_campaign_with_a_matter_fills_its_details(): void
    {
        $campaign = $this->campaign(
            $this->matter(),
            'Matter {{matter.reference}} — {{name}}',
            '<p>Court: {{matter.court}}. Next session {{ Matter.Next_Session_Date }}. Against {{matter.defendants}}. Expert {{matter.experts}}, assistant {{matter.assistants}}. Trustee {{matter.custom.trustee name}}.</p>',
        );
        $recipient = $this->recipient($campaign);

        $this->assertSame('Matter 125/2025 — Emirates Trading LLC', $campaign->renderSubject($recipient));
        $this->assertStringContainsString(
            'Court: Dubai Courts. Next session 05/10/2026. Against Defendant One, Defendant Two. Expert Expert Reda, assistant Assistant Sara. Trustee Ahmed Ali.',
            $campaign->renderBody($recipient),
        );
    }

    public function test_a_general_campaign_has_no_matter_placeholders(): void
    {
        $campaign = $this->campaign(null, 'Hello {{name}}', '<p>{{matter.court}} {{email}}</p>');
        $recipient = $this->recipient($campaign);

        $this->assertSame('Hello Emirates Trading LLC', $campaign->renderSubject($recipient));
        // Left as typed, so the preview shows it isn't filled.
        $this->assertStringContainsString('{{matter.court}} creditor@example.com', $campaign->renderBody($recipient));
    }

    public function test_imported_columns_fill_placeholders_and_beat_the_matter(): void
    {
        $campaign = $this->campaign($this->matter(), '{{matter.court}}', '<p>Claim {{Claim Amount}} for {{matter.reference}}</p>');
        $recipient = $this->recipient($campaign, ['claim_amount' => '5,000 AED', 'matter.court' => 'Abu Dhabi Courts']);

        $this->assertSame('Abu Dhabi Courts', $campaign->renderSubject($recipient));
        $this->assertStringContainsString('Claim 5,000 AED for 125/2025', $campaign->renderBody($recipient));
    }

    public function test_the_experts_registrations_are_read_once_a_request(): void
    {
        $experts = [['party_id' => 11], ['party_id' => 12]];
        DB::enableQueryLog();

        BulkMailPlaceholders::registrations($experts, 3);
        BulkMailPlaceholders::registrations($experts, 3);
        BulkMailPlaceholders::registrations($experts, 4);

        $this->assertCount(2, DB::getQueryLog());
    }

    public function test_a_placeholder_is_cut_to_characters_or_words(): void
    {
        $values = ['matter.plaintiffs' => 'شركة  الخليج للتجارة العامة ذ.م.م', 'name' => 'Smith & Sons'];

        $this->assertSame('شركة الخليج', BulkMailPlaceholders::apply('{{matter.plaintiffs,w:2}}', $values));
        $this->assertSame('شركة الخ', BulkMailPlaceholders::apply('{{ Matter.Plaintiffs , C:8 }}', $values));
        // The Arabic comma and digits too; a limit longer than the value changes nothing.
        $this->assertSame('شركة الخليج للتجارة العامة ذ.م.م', BulkMailPlaceholders::apply('{{matter.plaintiffs،w:٩}}', $values));
        // Escaped after being cut; one that matches nothing is left as written.
        $this->assertSame('Smith &amp;', BulkMailPlaceholders::apply('{{name,c:7}}', $values, escape: true));
        $this->assertSame('{{other,c:3}}', BulkMailPlaceholders::apply('{{other,c:3}}', $values));
        // A <<…>> part is kept by the value, cut or not.
        $this->assertSame('x Smith y', BulkMailPlaceholders::apply('x <<{{name,w:1}}>> y', $values));
        $this->assertSame('x y', BulkMailPlaceholders::apply('x <<{{name,w:1}}>> y', ['name' => '']));
    }

    public function test_values_are_escaped_in_the_body_but_not_the_subject(): void
    {
        $campaign = $this->campaign(null, '{{name}}', '<p>{{name}}</p>');
        $recipient = $this->recipient($campaign);
        $recipient->update(['name' => 'Smith & <Sons>']);

        $this->assertSame('Smith & <Sons>', $campaign->renderSubject($recipient));
        $this->assertStringContainsString('<p>Smith &amp; &lt;Sons&gt;</p>', $campaign->renderBody($recipient));
    }

    public function test_every_catalog_placeholder_resolves_for_a_matter(): void
    {
        $values = BulkMailPlaceholders::forMatter($this->matter());

        foreach (array_keys(BulkMailPlaceholders::matterCatalog()) as $key) {
            $this->assertArrayHasKey($key, $values);
        }

        $this->assertSame('Plaintiff Co', $values['matter.plaintiffs']);
        $this->assertSame('Plaintiff Co, Defendant One, Defendant Two', $values['matter.parties']);
        $this->assertNotSame('', $values['matter.status']);
    }

    public function test_assistants_are_named_by_their_legal_name_else_their_name(): void
    {
        $matter = Matter::factory()->create();
        $withLegal = Party::factory()->create(['name' => 'Amr', 'legal_name' => 'عمرو محمد عبد الله']);
        $without = Party::factory()->create(['name' => 'Nahla', 'legal_name' => null]);
        foreach ([$withLegal, $without] as $party) {
            MatterParty::create(['matter_id' => $matter->id, 'role' => 'expert', 'type' => 'assistant', 'party_id' => $party->id]);
        }

        $values = BulkMailPlaceholders::forMatter($matter->fresh());

        $this->assertSame('عمرو محمد عبد الله, Nahla', $values['matter.assistants']);
        $this->assertSame('2', $values['matter.assistants.count']);
        $this->assertSame('عمرو محمد عبد الله', $withLegal->legalName());
        $this->assertSame('Nahla', $without->legalName());
    }

    public function test_parties_saved_with_parent_id_zero_count_as_top_level(): void
    {
        // How older (live) matters store top-level parties.
        $matter = Matter::factory()->create();
        $plaintiff = MatterParty::create([
            'matter_id' => $matter->id,
            'party_id' => Party::factory()->create(['name' => 'Old Plaintiff'])->id,
            'role' => 'party', 'type' => 'plaintiff', 'parent_id' => 0,
        ]);
        MatterParty::create([
            'matter_id' => $matter->id,
            'party_id' => Party::factory()->create(['name' => 'Their Lawyer'])->id,
            'role' => 'representative', 'type' => 'lawyer', 'parent_id' => $plaintiff->id,
        ]);

        $values = BulkMailPlaceholders::forMatter($matter);

        $this->assertSame('Old Plaintiff', $values['matter.plaintiffs']);
        $this->assertSame('Old Plaintiff', $values['matter.parties']);
        $this->assertSame('Their Lawyer', $values['matter.representatives']);
    }

    public function test_the_preview_page_renders_a_recipient_with_several_emails(): void
    {
        $campaign = $this->campaign($this->matter(), 'Notice {{matter.reference}}', '<p>{{matter.defendants}}</p>');
        $recipient = $this->recipient($campaign);
        $recipient->update(['email' => ['a@example.com', 'b@example.com']]);
        $url = route('bulk-mail.preview', ['campaign' => $campaign->id, 'recipient' => $recipient->id]);

        // Only for whoever may see the campaign.
        $this->actingAs(User::factory()->create())->get($url)->assertForbidden();

        $this->actingAs(User::factory()->create()->assignRole(
            Role::firstOrCreate(['name' => config('filament-shield.super_admin.name', 'super_admin'), 'guard_name' => 'web'])
        ));
        $this->get($url)
            ->assertSuccessful()
            ->assertSee('a@example.com&gt;; &lt;b@example.com', false)
            ->assertSee('Notice 125/2025')
            ->assertSee('Defendant One, Defendant Two');

        // And only a recipient of that campaign.
        $other = $this->campaign($campaign->matter, 'Other', '<p>x</p>');
        $this->get(route('bulk-mail.preview', ['campaign' => $other->id, 'recipient' => $recipient->id]))->assertForbidden();
    }

    public function test_the_campaign_form_lists_the_chosen_matters_placeholders(): void
    {
        $this->actingAs(User::factory()->create()->assignRole(
            Role::firstOrCreate(['name' => config('filament-shield.super_admin.name', 'super_admin'), 'guard_name' => 'web'])
        ));
        Filament::setCurrentPanel('admin');
        $matter = $this->matter();

        Livewire::test(CreateBulkMailCampaign::class)
            ->assertSee('Choose a matter to add its details as placeholders.')
            ->fillForm(['matter_id' => $matter->id])
            ->assertSee('matter.defendants')
            ->assertSee('Defendant One, Defendant Two')
            ->assertSee('Dubai Courts');
    }

    public function test_the_editors_placeholder_menu_follows_the_form(): void
    {
        $this->actingAs(User::factory()->create()->assignRole(
            Role::firstOrCreate(['name' => config('filament-shield.super_admin.name', 'super_admin'), 'guard_name' => 'web'])
        ));
        Filament::setCurrentPanel('admin');

        // As the editor's data element writes a placeholder (its quotes escaped).
        $tag = fn (string $key): string => trim((string) json_encode(['key' => $key], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT), '{}');

        $page = Livewire::test(CreateBulkMailCampaign::class)
            ->assertSeeHtml($tag('name'))
            ->assertDontSeeHtml($tag('claim_amount'))
            ->assertDontSeeHtml($tag('matter.court'));

        // A key defined, and a matter chosen: both in the menu, read by the
        // editor each time it opens — no need to draw it again.
        $page->fillForm(['placeholders' => ['claim_amount'], 'matter_id' => $this->matter()->id])
            ->assertSeeHtml($tag('claim_amount'))
            ->assertSeeHtml($tag('matter.court'))
            ->assertSeeHtml($tag('matter.custom.Trustee Name'));

        // Removed again: gone from the menu.
        $page->fillForm(['placeholders' => []])->assertDontSeeHtml($tag('claim_amount'));
    }

    public function test_placeholders_inserted_from_the_menu_are_filled(): void
    {
        $matter = $this->matter();
        $campaign = $this->campaign(
            $matter,
            'Notice',
            '<p>Dear <span data-type="mergeTag" data-id="name">Recipient name</span>, matter <span data-type="mergeTag" data-id="matter.reference">Matter number/year</span>.</p>',
        );

        $this->assertStringContainsString('Dear Emirates Trading LLC, matter 125/2025.', $campaign->renderBody($this->recipient($campaign)));

        // As TinyMCE keeps them: typed {{key}}.
        $campaign = $this->campaign($matter, 'Notice', '<p>Dear {{name}}, matter {{matter.reference}}.</p>');
        $this->assertStringContainsString('Dear Emirates Trading LLC, matter 125/2025.', $campaign->renderBody($this->recipient($campaign)));
    }

    public function test_the_editor_has_left_right_and_justify_and_reads_as_typed(): void
    {
        $this->actingAs(User::factory()->create()->assignRole(
            Role::firstOrCreate(['name' => config('filament-shield.super_admin.name', 'super_admin'), 'guard_name' => 'web'])
        ));
        Filament::setCurrentPanel('admin');

        $html = Livewire::test(CreateBulkMailCampaign::class)->html();

        // The sides of the page, and each paragraph's direction.
        $this->assertStringContainsString('alignleft aligncenter alignright alignjustify ltr rtl', $html);
        // No language of its own: Arabic typed in the English interface runs right to left.
        $this->assertStringContainsString('unicode-bidi: plaintext', $html);
    }

    public function test_the_alignment_goes_out_as_left_and_right(): void
    {
        // Arabic: the start is the right.
        $campaign = $this->campaign(null, 'إشعار', '<p style="text-align: start">السادة/ {{name}}</p><p style="text-align: end">مع التحية</p><p style="text-align: justify">نص</p>');
        $this->assertStringContainsString(
            '<p style="text-align: right">السادة/ Emirates Trading LLC</p><p style="text-align: left">مع التحية</p><p style="text-align: justify">نص</p>',
            $campaign->renderBody($this->recipient($campaign)),
        );

        $campaign = $this->campaign(null, 'Notice', '<p style="text-align: start">Dear {{name}}</p><p style="text-align: end">Regards</p>');
        $this->assertStringContainsString(
            '<p style="text-align: left">Dear Emirates Trading LLC</p><p style="text-align: right">Regards</p>',
            $campaign->renderBody($this->recipient($campaign)),
        );
    }

    public function test_the_import_keeps_every_extra_column_as_a_placeholder(): void
    {
        $campaign = $this->campaign(null, 's', 'b');
        $import = Import::create([
            'file_name' => 'recipients.csv', 'file_path' => 'recipients.csv', 'importer' => BulkMailRecipientImporter::class,
            'total_rows' => 1, 'user_id' => User::factory()->create()->id,
        ]);

        $importer = new BulkMailRecipientImporter($import, ['name' => 'Company', 'email' => 'Email'], ['campaign_id' => $campaign->id]);
        $importer([
            'Company' => 'Gulf Supplies',
            'Email' => 'gulf@example.com',
            'Claim Amount' => ' 12,500 ',
            'رقم المطالبة' => 'C-77',
        ]);

        $recipient = $campaign->recipients()->sole();

        $this->assertSame('Gulf Supplies', $recipient->name);
        $this->assertSame(['claim_amount' => '12,500', 'رقم_المطالبة' => 'C-77'], $recipient->placeholders);
    }
}
