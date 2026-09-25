<?php

namespace Tests\Feature;

use App\Enums\BulkMailCampaignStatus;
use App\Enums\BulkMailRecipientStatus;
use App\Filament\Mms\Resources\BulkMailCampaigns\Pages\ViewBulkMailCampaign;
use App\Filament\Mms\Resources\BulkMailCampaigns\RelationManagers\RecipientsRelationManager;
use App\Models\BulkMailCampaign;
use App\Models\BulkMailRecipient;
use App\Models\User;
use App\Services\MMS\BulkMailService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use ZipArchive;

class BulkMailPdfTest extends TestCase
{
    use RefreshDatabase;

    private BulkMailCampaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        config(['mail_senders.senders.test' => [
            'username' => 'sender@example.com',
            'address' => 'sender@example.com',
            'name' => 'Test Sender',
            'password' => 'secret',
            'host' => 'mail.example.com',
            'port' => 587,
            'encryption' => 'tls',
        ]]);

        $this->campaign = BulkMailCampaign::create([
            'name' => 'Creditors notice',
            'subject' => 'Notice of the creditors meeting for {{name}}',
            'body' => '<p>Dear {{name}}</p>',
            'from_sender_key' => 'test',
            'daily_send_limit' => 60,
            'status' => BulkMailCampaignStatus::Active,
            'created_by' => User::factory()->create()->id,
        ]);
    }

    private function recipient(string $name, ?string $sentAt = '2026-09-25 10:00:00'): BulkMailRecipient
    {
        return BulkMailRecipient::create([
            'campaign_id' => $this->campaign->id,
            'email' => [str($name)->slug().'@example.com'],
            'name' => $name,
            'status' => $sentAt ? BulkMailRecipientStatus::Sent : BulkMailRecipientStatus::Pending,
            'sent_at' => $sentAt,
        ]);
    }

    public function test_the_file_name_is_date_recipient_and_subject_trimmed_to_75_characters(): void
    {
        $name = BulkMailService::pdfFileName($this->recipient('Emirates Trading LLC'));

        $this->assertStringStartsWith('2026-09-25 Emirates Trading LLC Notice of the creditors meeting', $name);
        $this->assertStringEndsWith('.pdf', $name);
        $this->assertSame(75, mb_strlen($name) - 4);
    }

    public function test_arabic_names_and_forbidden_characters(): void
    {
        $name = BulkMailService::pdfFileName($this->recipient('شركة الإمارات: فرع/دبي'));

        $this->assertStringStartsWith('2026-09-25 شركة الإمارات فرع دبي Notice', $name);
        $this->assertDoesNotMatchRegularExpression('#[\\\\/:*?"<>|]#', $name);
        $this->assertLessThanOrEqual(75, mb_strlen($name) - 4);
    }

    public function test_the_zip_holds_every_sent_pdf_under_its_readable_name(): void
    {
        $this->recipient('Alpha LLC');
        $this->recipient('Alpha LLC'); // same name, date and subject
        $this->recipient('Beta LLC');
        $this->recipient('Not sent yet', sentAt: null);

        $zipPath = app(BulkMailService::class)->zip($this->campaign->recipients()->orderBy('id')->get());

        $zip = new ZipArchive;
        $zip->open($zipPath);
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }

        $this->assertCount(3, $names);
        $this->assertStringStartsWith('2026-09-25 Alpha LLC Notice', $names[0]);
        $this->assertStringEndsWith(' (2).pdf', $names[1]);
        $this->assertStringStartsWith('2026-09-25 Beta LLC Notice', $names[2]);
        $this->assertStringStartsWith('%PDF', $zip->getFromIndex(0));
        $zip->close();
        @unlink($zipPath);

        // The missing PDFs were generated, stored by id, and remembered.
        $this->assertSame(3, $this->campaign->recipients()->whereNotNull('pdf_path')->count());
        Storage::disk('public')->assertExists($this->campaign->recipients()->first()->pdf_path);
    }

    public function test_nothing_sent_gives_no_zip(): void
    {
        $this->recipient('Not sent yet', sentAt: null);

        $this->assertNull(app(BulkMailService::class)->zip($this->campaign->recipients));
    }

    public function test_one_pdf_downloads_under_its_readable_name(): void
    {
        $recipient = $this->recipient('Alpha LLC');
        $this->actingAs(User::factory()->create()->assignRole(
            Role::firstOrCreate(['name' => config('filament-shield.super_admin.name', 'super_admin'), 'guard_name' => 'web'])
        ));

        $response = $this->get(route('bulk-mail.pdf', $recipient))->assertSuccessful();

        $this->assertStringContainsString('2026-09-25 Alpha LLC Notice', rawurldecode($response->headers->get('content-disposition')));
    }

    public function test_the_recipients_table_downloads_all_pdfs_and_resends_a_failed_one(): void
    {
        $this->actingAs(User::factory()->create()->assignRole(
            Role::firstOrCreate(['name' => config('filament-shield.super_admin.name', 'super_admin'), 'guard_name' => 'web'])
        ));
        Filament::setCurrentPanel('admin');

        $this->recipient('Alpha LLC');
        $failed = $this->recipient('Beta LLC', sentAt: null);
        $failed->update(['status' => BulkMailRecipientStatus::Failed, 'attempt_count' => 3, 'failed_at' => now()]);
        $this->campaign->update(['sent_count' => 1, 'failed_count' => 1]);

        $table = Livewire::test(RecipientsRelationManager::class, [
            'ownerRecord' => $this->campaign,
            'pageClass' => ViewBulkMailCampaign::class,
        ]);

        $table->callTableAction('downloadAllPdfs')->assertFileDownloaded('creditors-notice-'.now()->format('Y-m-d').'.zip');

        $table->assertTableActionVisible('resend', $failed)
            ->callTableAction('resend', $failed);

        $this->assertSame(BulkMailRecipientStatus::Pending, $failed->fresh()->status);
        $this->assertSame(0, $failed->fresh()->attempt_count);
        $this->assertSame(0, $this->campaign->fresh()->failed_count);
    }
}
