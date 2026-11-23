<?php

namespace Tests\Feature;

use App\Enums\AttachmentCategory;
use App\Enums\RequestStatus;
use App\Enums\RequestType;
use App\Mail\NewRequestNotificationMail;
use App\Models\Matter;
use App\Models\MatterRequest;
use App\Models\Setting;
use App\Models\User;
use App\Services\Attachments\AttachmentService;
use App\Services\MMS\Letters\SharedAttachments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A request's email carries its files as far as an email takes (Settings → Letters & emails): the rest are only
 * linked, and the email says so — all of them attached, a report with its annexes ran past PHP's memory while
 * Microsoft Graph wrote the email out, and nobody was told of the request.
 */
class RequestMailFilesTest extends TestCase
{
    use RefreshDatabase;

    public function test_files_past_what_an_email_takes_are_linked_not_attached(): void
    {
        app()->setLocale('en');
        Storage::fake('attachments');
        Storage::fake('public');
        Setting::clearCache();
        Setting::set(SharedAttachments::LIMIT, 1, 'mail');

        $user = User::factory()->create();
        $this->actingAs($user);
        $matter = Matter::factory()->create();
        $request = MatterRequest::create(['matter_id' => $matter->id, 'request_by' => $user->id, 'type' => RequestType::REVIEW_REPORT, 'status' => RequestStatus::PENDING, 'comment' => 'x']);

        // Two files of 600 KB each, in 1 MB: the first attached, the second only linked.
        foreach (['report.docx', 'annex.pdf'] as $name) {
            app(AttachmentService::class)->store($request, str_repeat('a', 600 * 1024), AttachmentCategory::REQUEST, $name, $user);
        }

        $mail = new NewRequestNotificationMail($matter, $request->fresh());
        $attached = $mail->attachments();

        $this->assertCount(1, $attached);
        $this->assertSame(1, $mail->filesLeft);
        $mail->assertSeeInHtml('1 file is not attached');
        $mail->assertSeeInHtml('annex.pdf');
    }
}
