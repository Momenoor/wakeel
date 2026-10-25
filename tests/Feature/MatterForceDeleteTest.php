<?php

namespace Tests\Feature;

use App\Enums\AttachmentCategory as Category;
use App\Enums\FeeType;
use App\Models\Attachment;
use App\Models\Matter;
use App\Models\User;
use App\Services\Attachments\AttachmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A matter deleted for good goes, whatever files it has: each deleted with its file — those already hidden too —
 * except financial evidence, which is only hidden and kept, of no matter.
 */
class MatterForceDeleteTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_matter_with_files_and_financial_evidence_is_deleted_for_good(): void
    {
        Storage::fake('attachments');
        $this->actingAs(User::factory()->create());
        $files = app(AttachmentService::class);

        $matter = Matter::factory()->create();
        $fee = $matter->fees()->create(['type' => FeeType::cases()[0], 'amount' => 1000, 'date' => '2026-01-10']);
        $invoice = $files->store($fee, UploadedFile::fake()->create('Invoice.pdf', 5, 'application/pdf'), Category::TAX_INVOICE);
        $letter = $files->store($matter, UploadedFile::fake()->create('Letter.pdf', 5, 'application/pdf'), Category::GENERAL);
        // Hidden before: an invoice deleted from the matter's page.
        $hidden = $files->store($fee, UploadedFile::fake()->create('Old invoice.pdf', 5, 'application/pdf'), Category::TAX_INVOICE);
        $hidden->delete();

        $matter->delete();
        $matter->forceDelete();

        $this->assertNull(Matter::withTrashed()->find($matter->id));
        // Its other files go, row and file.
        $this->assertNull(Attachment::withTrashed()->find($letter->id));
        Storage::disk('attachments')->assertMissing($letter->path);
        // Financial evidence stays, hidden, its file kept.
        foreach ([$invoice, $hidden] as $evidence) {
            $kept = Attachment::withTrashed()->findOrFail($evidence->id);
            $this->assertTrue($kept->trashed());
            $this->assertNull($kept->matter_id);
            Storage::disk('attachments')->assertExists($kept->path);
        }
    }
}
