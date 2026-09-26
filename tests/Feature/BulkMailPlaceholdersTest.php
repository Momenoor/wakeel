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
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class BulkMailPlaceholdersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['mail_senders.senders.test' => [
            'username' => 'sender@example.com', 'address' => 'sender@example.com', 'name' => 'Test Sender',
            'password' => 'secret', 'host' => 'mail.example.com', 'port' => 587, 'encryption' => 'tls',
        ]]);
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
        $this->actingAs(User::factory()->create());
        $campaign = $this->campaign($this->matter(), 'Notice {{matter.reference}}', '<p>{{matter.defendants}}</p>');
        $recipient = $this->recipient($campaign);
        $recipient->update(['email' => ['a@example.com', 'b@example.com']]);

        $this->get(route('bulk-mail.preview', ['campaign' => $campaign->id, 'recipient' => $recipient->id]))
            ->assertSuccessful()
            ->assertSee('a@example.com&gt;; &lt;b@example.com', false)
            ->assertSee('Notice 125/2025')
            ->assertSee('Defendant One, Defendant Two');
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
