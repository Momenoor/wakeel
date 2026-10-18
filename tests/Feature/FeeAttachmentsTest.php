<?php

namespace Tests\Feature;

use App\Enums\AttachmentCategory as Category;
use App\Enums\FeeType;
use App\Filament\Mms\Resources\Matters\Pages\ViewMatter;
use App\Models\Allocation;
use App\Models\Attachment;
use App\Models\Fee;
use App\Models\Matter;
use App\Models\User;
use App\Services\Attachments\AttachmentService;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A fee's invoice and a payment's receipt, from the matter's page: given with the fee or the payment, or
 * attached later on its row; listed there, opened through the controller, and an invoice deleted is only
 * hidden.
 */
class FeeAttachmentsTest extends TestCase
{
    use RefreshDatabase;

    private Matter $matter;

    private Fee $fee;

    protected function setUp(): void
    {
        parent::setUp();

        Gate::before(fn () => true);
        Storage::fake('local');
        Filament::setCurrentPanel('mms');
        $this->actingAs(User::factory()->create());

        $this->matter = Matter::factory()->create();
        $this->fee = $this->matter->fees()->create(['type' => FeeType::cases()[0], 'amount' => 1000, 'date' => '2026-01-10']);
    }

    private function page()
    {
        return Livewire::test(ViewMatter::class, ['record' => $this->matter->getRouteKey()]);
    }

    private function pdf(string $name = 'Invoice.pdf'): UploadedFile
    {
        return UploadedFile::fake()->create($name, 5, 'application/pdf');
    }

    public function test_a_new_fee_takes_its_invoice_and_one_without_files_still_saves(): void
    {
        $this->page()
            ->callAction(TestAction::make('create_fee')->schemaComponent('feesSection', 'infolist'), data: [
                'type' => FeeType::cases()[0]->value, 'amount' => 500, 'date' => '2026-02-01', 'description' => 'Second stage',
                'files' => [$this->pdf()], 'file_category' => Category::TAX_INVOICE->value,
            ])
            ->assertHasNoActionErrors();

        $fee = $this->matter->fees()->latest('id')->first();
        $file = $fee->ownedAttachments()->sole();
        // The fee's description is its own, not the file's.
        $this->assertSame(['Second stage', null], [$fee->description, $file->description]);
        $this->assertSame([Category::TAX_INVOICE, 'attachments', $this->matter->id, 'Invoice.pdf'], [$file->category, $file->disk, $file->matter_id, $file->name]);
        Storage::disk('attachments')->assertExists($file->path);

        $this->page()
            ->callAction(TestAction::make('create_fee')->schemaComponent('feesSection', 'infolist'), data: [
                'type' => FeeType::cases()[0]->value, 'amount' => 200, 'date' => '2026-02-02', 'files' => [],
            ])
            ->assertHasNoActionErrors();
        $this->assertSame(3, $this->matter->fees()->count());
        $this->assertSame(1, Attachment::count());
    }

    public function test_a_payment_takes_its_receipt(): void
    {
        $this->page()
            ->callAction(TestAction::make('collect_fee')->schemaComponent('fees.0', 'infolist'), data: [
                'amount' => 400, 'date' => '2026-01-20', 'files' => [$this->pdf('Transfer.pdf')], 'file_category' => Category::BANK_TRANSFER->value,
            ])
            ->assertHasNoActionErrors();

        $allocation = $this->fee->allocations()->sole();
        $this->assertSame([Allocation::class, Category::BANK_TRANSFER], [$allocation->ownedAttachments()->sole()->attachable_type, $allocation->ownedAttachments()->sole()->category]);
    }

    public function test_files_are_attached_later_listed_and_an_invoice_deleted_is_only_hidden(): void
    {
        $this->page()
            ->callAction(TestAction::make('attachFeeFiles')->schemaComponent('fees.0', 'infolist'), data: ['files' => [$this->pdf()], 'category' => Category::FEE_INVOICE->value])
            ->assertHasNoActionErrors();

        $file = $this->fee->ownedAttachments()->sole();

        // On the fee's row, opened through the controller (in the browser: a PDF).
        $this->page()->assertSee('Invoice.pdf')->assertSee($file->url(inline: true), escape: false);

        $this->page()->callAction(TestAction::make('deleteOwnedAttachment')->schemaComponent('fees.0.ownedAttachments.0', 'infolist'));

        $this->assertSoftDeleted($file);
        Storage::disk('attachments')->assertExists($file->path);
        $this->assertSame(0, $this->fee->ownedAttachments()->count());
    }

    public function test_a_payments_receipt_is_on_its_row(): void
    {
        $allocation = Allocation::create(['matter_id' => $this->matter->id, 'fee_id' => $this->fee->id, 'amount' => 100, 'date' => '2026-01-15']);
        app(AttachmentService::class)->store($allocation, '%PDF', Category::CHEQUE, 'Cheque 778.pdf');

        $this->page()->assertSee('Cheque 778.pdf')->assertSee(__('Receipts'));
    }
}
