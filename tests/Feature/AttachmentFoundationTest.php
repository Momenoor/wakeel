<?php

namespace Tests\Feature;

use App\Enums\AttachmentCategory as Category;
use App\Models\Allocation;
use App\Models\Attachment;
use App\Models\Fee;
use App\Models\Matter;
use App\Models\MatterRequest;
use App\Models\User;
use App\Services\Attachments\AttachmentService;
use App\Support\FileName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Files attached to anything: owned by a record, in its matter's context, categorised, on the private disk,
 * opened only by who may — and the old way of attaching to a matter or a request still works.
 */
class AttachmentFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('attachments');
        Storage::fake('public');
    }

    private function fee(): Fee
    {
        return Fee::factory()->create(['matter_id' => Matter::factory()->create()->id]);
    }

    public function test_a_file_attached_the_old_way_is_still_owned_and_categorised(): void
    {
        $matter = Matter::factory()->create();
        $user = User::factory()->create();
        $request = MatterRequest::create(['matter_id' => $matter->id, 'request_by' => $user->id, 'type' => 'review_report', 'status' => 'pending', 'comment' => 'x']);

        $onMatter = $matter->attachments()->create(['user_id' => $user->id, 'name' => 'a.pdf', 'path' => 'x/a.pdf', 'size' => '1234', 'extension' => 'pdf', 'type' => 'correspondence']);
        $onRequest = $request->attachments()->create(['matter_id' => $matter->id, 'user_id' => $user->id, 'name' => 'b.pdf', 'path' => 'x/b.pdf', 'size' => '10', 'extension' => 'pdf', 'type' => 'matter-request']);

        $this->assertSame([Matter::class, $matter->id, Category::CORRESPONDENCE, 1234, 'public'], [$onMatter->attachable_type, $onMatter->attachable_id, $onMatter->category, $onMatter->size_bytes, $onMatter->disk]);
        $this->assertSame([MatterRequest::class, $request->id, Category::REQUEST], [$onRequest->attachable_type, $onRequest->attachable_id, $onRequest->category]);
        // The matter's attachments are still every file of the matter, a request's too.
        $this->assertSame(2, $matter->attachments()->count());
        $this->assertSame(1, $matter->ownedAttachments()->count());
    }

    public function test_a_fee_or_an_allocation_takes_a_file_on_the_private_disk_in_its_matters_context(): void
    {
        $fee = $this->fee();
        $allocation = Allocation::factory()->create(['fee_id' => $fee->id, 'matter_id' => $fee->matter_id]);
        $service = app(AttachmentService::class);
        $by = User::factory()->create();

        $invoice = $service->store($fee, '%PDF-invoice', Category::FEE_INVOICE, 'Invoice 12/2026.pdf', $by, 'The registered fee');
        $receipt = $service->store($allocation, 'receipt-bytes', Category::PAYMENT_RECEIPT, 'نموذج إيصال.pdf', $by);

        foreach ([$invoice, $receipt] as $file) {
            $this->assertSame($fee->matter_id, $file->matter_id);
            $this->assertSame('attachments', $file->disk);
            $this->assertSame($by->id, $file->user_id);
            // Where it is says nothing of what it is.
            $this->assertMatchesRegularExpression('~^(fee|allocation)/\d{4}/\d+/[0-9a-f-]{36}\.pdf$~', $file->path);
            Storage::disk('attachments')->assertExists($file->path);
            Storage::disk('public')->assertMissing($file->path);
        }
        $this->assertSame([$fee::class, $fee->id, hash('sha256', '%PDF-invoice'), 12, 'The registered fee'], [$invoice->attachable_type, $invoice->attachable_id, $invoice->checksum, $invoice->size_bytes, $invoice->description]);
        $this->assertSame('نموذج إيصال.pdf', $receipt->name);
        // The matter lists them with its own files.
        $this->assertSame(2, Attachment::where('matter_id', $fee->matter_id)->count());
        $this->assertSame(1, $fee->ownedAttachments()->count());
    }

    public function test_only_what_the_record_allows_and_a_kind_of_file_that_is_safe(): void
    {
        $fee = $this->fee();
        $service = app(AttachmentService::class);

        // A receipt belongs to an allocation, not a fee.
        try {
            $service->store($fee, 'x', Category::PAYMENT_RECEIPT, 'r.pdf');
            $this->fail('A category the fee does not take was accepted.');
        } catch (InvalidArgumentException) {
        }

        // A program or a script is never attached.
        foreach (['run.exe', 'x.php', 'noextension'] as $name) {
            try {
                $service->store($fee, 'x', Category::GENERAL, $name);
                $this->fail("$name was accepted.");
            } catch (InvalidArgumentException) {
            }
        }

        // Too big.
        config(['attachments.max_kb' => 1]);
        $this->expectException(InvalidArgumentException::class);
        $service->store($fee, str_repeat('a', 2048), Category::GENERAL, 'big.pdf');
    }

    public function test_a_file_already_stored_is_taken_in_and_moved_to_the_private_disk(): void
    {
        $fee = $this->fee();
        Storage::disk('public')->put('requests-attachments/upload.pdf', 'uploaded');

        $file = app(AttachmentService::class)->adopt($fee, 'requests-attachments/upload.pdf', Category::TAX_INVOICE, name: 'Tax invoice.pdf');

        Storage::disk('public')->assertMissing('requests-attachments/upload.pdf');
        Storage::disk('attachments')->assertExists($file->path);
        $this->assertSame('uploaded', Storage::disk('attachments')->get($file->path));
        $this->assertSame('attachments', $file->disk);
    }

    public function test_the_same_content_on_the_same_record_is_noticed(): void
    {
        $fee = $this->fee();
        $service = app(AttachmentService::class);
        $first = $service->store($fee, 'same bytes', Category::GENERAL, 'a.pdf');

        $this->assertSame($first->id, $service->duplicateOf($fee, 'same bytes')?->id);
        $this->assertNull($service->duplicateOf($fee, 'other bytes'));
    }

    public function test_a_file_opens_for_whoever_may_see_its_matter_and_for_nobody_else(): void
    {
        $fee = $this->fee();
        $file = app(AttachmentService::class)->store($fee, '%PDF-body', Category::GENERAL, 'Report 5/2026.pdf');
        $stranger = User::factory()->create();

        // Nobody signed in: sent to sign in. Someone who may not see the matter: refused.
        $this->get($file->url())->assertRedirect();
        $this->actingAs($stranger)->get($file->url())->assertForbidden();

        Gate::before(fn () => true);
        $safe = FileName::safe('Report 5/2026.pdf', 'x');
        $this->get($file->url())->assertOk()->assertDownload($safe);
        // In the browser, for a PDF or a picture.
        $this->get($file->url(inline: true))->assertOk()->assertHeader('content-disposition', 'inline; filename="'.$safe.'"');
    }

    public function test_a_file_from_before_still_opens_from_the_public_disk(): void
    {
        Gate::before(fn () => true);
        $matter = Matter::factory()->create();
        Storage::disk('public')->put('attachments/emails/1/old.pdf', 'old file');
        $file = $matter->attachments()->create(['user_id' => User::factory()->create()->id, 'name' => 'old.pdf', 'path' => 'attachments/emails/1/old.pdf', 'size' => '8', 'extension' => 'pdf', 'type' => 'correspondence']);

        $this->actingAs(User::factory()->create())->get($file->url())->assertOk()->assertDownload('old.pdf');
        // A file gone from the disk is not found, not an error.
        Storage::disk('public')->delete($file->path);
        $this->get($file->url())->assertNotFound();
    }

    public function test_financial_evidence_is_hidden_but_kept_and_anything_else_goes_with_its_file(): void
    {
        $fee = $this->fee();
        $service = app(AttachmentService::class);
        $evidence = $service->store($fee, 'bytes', Category::FEE_INVOICE, 'invoice.pdf');
        $general = $service->store($fee, 'bytes2', Category::GENERAL, 'note.pdf');

        $evidence->delete();
        $general->delete();

        // The invoice: still there, its file too, only hidden.
        $this->assertSoftDeleted($evidence);
        Storage::disk('attachments')->assertExists($evidence->path);
        $this->assertSame(0, $fee->ownedAttachments()->count());
        $this->assertSame(1, $fee->ownedAttachments()->withTrashed()->count());
        // The note: row and file gone, as before.
        $this->assertDatabaseMissing('attachments', ['id' => $general->id]);
        Storage::disk('attachments')->assertMissing($general->path);
    }
}
