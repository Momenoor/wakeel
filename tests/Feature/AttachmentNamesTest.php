<?php

namespace Tests\Feature;

use App\Enums\BulkMailCampaignStatus;
use App\Enums\BulkMailRecipientStatus;
use App\Http\Controllers\LegacyAttachmentPathController;
use App\Mail\BulkMailMessage;
use App\Models\BulkMailCampaign;
use App\Models\BulkMailRecipient;
use App\Models\Matter;
use App\Models\MatterEmail;
use App\Models\User;
use App\Services\MMS\SentEmailArchive;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * A file of an email or a bulk mail is kept under a name of the system's own; it is sent, listed, shown and
 * saved under its own — the one it was uploaded, came or was sent with.
 */
class AttachmentNamesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Filament::setCurrentPanel(Filament::getPanel('mms'));
        $admin = User::factory()->create();
        $admin->assignRole(Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']));
        $this->actingAs($admin);
    }

    public function test_a_bulk_mail_file_goes_out_and_is_listed_under_the_name_it_was_uploaded_with(): void
    {
        Storage::disk('public')->put('mail_attachments/01JX7K2Q.pdf', '%PDF-a');
        Storage::disk('public')->put('mail_attachments/imported/abc/notice.pdf', '%PDF-b');

        $campaign = BulkMailCampaign::create([
            'name' => 'c', 'subject' => 's', 'body' => '<p>b</p>', 'from_sender_key' => 'test', 'daily_send_limit' => 10,
            'status' => BulkMailCampaignStatus::Active, 'created_by' => auth()->id(), 'has_attachment' => true,
            'attachment_path' => ['mail_attachments/01JX7K2Q.pdf', 'mail_attachments/imported/abc/notice.pdf'],
            'attachment_names' => ['mail_attachments/01JX7K2Q.pdf' => 'دعوة اجتماع الدائنين.pdf'],
        ]);
        $recipient = BulkMailRecipient::create(['campaign_id' => $campaign->id, 'email' => ['a@example.com'], 'name' => 'A', 'status' => BulkMailRecipientStatus::Pending]);

        // Sent under it; one of before (imported) under the name it is kept under, its own.
        $sent = array_map(fn ($attachment): string => $attachment->as, (new BulkMailMessage($campaign, $recipient))->attachments());
        $this->assertSame(['دعوة اجتماع الدائنين.pdf', 'notice.pdf'], $sent);

        // Listed in the email's PDF and its preview by it, with its size.
        $this->assertSame([['name' => 'دعوة اجتماع الدائنين.pdf', 'size' => 6], ['name' => 'notice.pdf', 'size' => 6]], $campaign->attachmentFilesFor($recipient));
    }

    public function test_an_emails_file_opens_and_saves_under_its_own_name(): void
    {
        Storage::disk('public')->put('email-unfiled/1/20261118-101500-a1b2c3.pdf', '%PDF-x');
        $email = MatterEmail::create([
            'direction' => MatterEmail::RECEIVED, 'status' => MatterEmail::PENDING, 'sender_key' => 'iflas', 'subject' => 's', 'from' => 'a@b.ae', 'at' => now(),
            'files' => [['name' => 'email.pdf', 'path' => 'email-unfiled/1/x.pdf'], ['name' => 'فاتورة #5?.pdf', 'path' => 'email-unfiled/1/20261118-101500-a1b2c3.pdf']],
        ]);

        $url = $email->fileLinks(attachmentsOnly: true)[0]['url'];
        $this->assertStringContainsString('/files/email-unfiled/1/20261118-101500-a1b2c3.pdf?name=', $url);

        $response = $this->get($url)->assertOk();
        $this->assertStringStartsWith('inline;', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString("filename*=utf-8''".rawurlencode('فاتورة #5?.pdf'), (string) $response->headers->get('Content-Disposition'));

        // Not for whoever may not see it.
        $this->actingAs(User::factory()->create())->get($url)->assertForbidden();
    }

    public function test_the_name_given_keeps_the_files_kind_and_one_of_none_is_its_attachments(): void
    {
        $this->assertSame('Invoice 5.pdf', LegacyAttachmentPathController::name('a/1-x.pdf', 'Invoice 5'));
        $this->assertSame('a b.pdf', LegacyAttachmentPathController::name('a/1-x.pdf', 'a/b.pdf'));
        $this->assertSame('1-x.pdf', LegacyAttachmentPathController::name('a/1-x.pdf', null));

        $matter = Matter::factory()->create();
        $path = app(SentEmailArchive::class)->keepFile($matter, 'تقرير الخبير.pdf', '%PDF-r', 'application/pdf', '', auth()->id());
        $this->assertSame('تقرير الخبير.pdf', LegacyAttachmentPathController::name($path, ''));
    }
}
