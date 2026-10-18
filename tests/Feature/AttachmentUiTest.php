<?php

namespace Tests\Feature;

use App\Enums\AttachmentCategory as Category;
use App\Enums\RequestStatus;
use App\Enums\RequestType;
use App\Filament\Mms\Actions\Request\ApproveRequestAction;
use App\Filament\Mms\Actions\Request\CreateRequestAction;
use App\Filament\Mms\Actions\Request\RejectRequestAction;
use App\Filament\Mms\RelationManagers\AttachmentsRelationManager;
use App\Filament\Mms\Resources\MatterRequests\Pages\ViewMatterRequest;
use App\Filament\Mms\Resources\Matters\Pages\ViewMatter;
use App\Filament\Support\AttachFilesAction;
use App\Filament\Support\AttachmentCount;
use App\Filament\Support\AttachmentFields;
use App\Models\Fee;
use App\Models\Matter;
use App\Models\MatterRequest;
use App\Models\User;
use App\Services\Attachments\AttachmentService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The reusable way of attaching files: the fields and "Attach files" on any record, the attachments list with
 * its filters and delete, and the request actions that now use them.
 */
class AttachmentUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('attachments');
        Storage::fake('local');
        Storage::fake('public');
        $this->actingAs(User::factory()->create());
    }

    /** Gate::before callbacks accumulate and the first answer wins: only the tests that are not about being refused add it. */
    private function allowEverything(): void
    {
        Gate::before(fn () => true);
    }

    private function fee(): Fee
    {
        return Fee::factory()->create(['matter_id' => Matter::factory()->create()->id]);
    }

    /** The attachments list of a record, as a page would mount it. */
    private function list(Fee|MatterRequest $owner)
    {
        return Livewire::test(AttachmentsRelationManager::class, ['ownerRecord' => $owner, 'pageClass' => ViewMatter::class]);
    }

    private function pdf(string $name = 'invoice.pdf', int $kb = 5): UploadedFile
    {
        return UploadedFile::fake()->create($name, $kb, 'application/pdf');
    }

    public function test_attach_files_puts_the_uploads_on_the_record_in_the_private_disk(): void
    {
        $this->allowEverything();
        $fee = $this->fee();

        $this->list($fee)
            ->callAction(TestAction::make('attachFiles')->table(), data: [
                'files' => [$this->pdf('Invoice 12.pdf'), $this->pdf('Tax.pdf', 6)],
                'category' => Category::TAX_INVOICE->value,
                'description' => 'As registered',
            ])
            ->assertHasNoFormErrors()
            ->assertNotified();

        $files = $fee->ownedAttachments()->orderBy('id')->get();
        $this->assertSame(['Invoice 12.pdf', 'Tax.pdf'], $files->pluck('name')->all());
        foreach ($files as $file) {
            $this->assertSame([Category::TAX_INVOICE, 'attachments', 'user', 'As registered', $fee->matter_id], [$file->category, $file->disk, $file->source, $file->description, $file->matter_id]);
            Storage::disk('attachments')->assertExists($file->path);
        }
        // The upload's waiting place is emptied.
        $this->assertSame([], Storage::disk('local')->allFiles(AttachmentFields::DIRECTORY));
        $this->assertSame([], Storage::disk('local')->allDirectories(AttachmentFields::DIRECTORY));
    }

    public function test_the_category_is_limited_to_what_the_record_allows(): void
    {
        $this->allowEverything();
        $fee = $this->fee();

        // A receipt belongs to an allocation, not a fee.
        $this->list($fee)
            ->callAction(TestAction::make('attachFiles')->table(), data: ['files' => [$this->pdf()], 'category' => Category::PAYMENT_RECEIPT->value])
            ->assertHasFormErrors(['category']);
        $this->assertSame(0, $fee->ownedAttachments()->count());

        $this->assertSame(
            [Category::FEE_INVOICE, Category::TAX_INVOICE, Category::CONTRACT, Category::GENERAL],
            AttachmentFields::categories($fee),
        );
    }

    public function test_a_form_without_a_category_field_takes_the_one_given_or_the_records_first(): void
    {
        $this->allowEverything();
        $fee = $this->fee();
        Storage::disk('local')->put(AttachmentFields::DIRECTORY.'/a/first.pdf', 'one');
        Storage::disk('local')->put(AttachmentFields::DIRECTORY.'/b/second.pdf', 'two');

        $first = AttachmentFields::save($fee, ['files' => [AttachmentFields::DIRECTORY.'/a/first.pdf']])->sole();
        $second = AttachmentFields::save($fee, ['files' => [AttachmentFields::DIRECTORY.'/b/second.pdf']], category: Category::CONTRACT)->sole();

        $this->assertSame([Category::FEE_INVOICE, Category::CONTRACT], [$first->category, $second->category]);
    }

    public function test_the_matters_attach_button_offers_the_matters_categories_and_attaches_to_it(): void
    {
        $this->allowEverything();
        $matter = Matter::factory()->create();
        Storage::disk('local')->put(AttachmentFields::DIRECTORY.'/a/Minutes.pdf', '%PDF-minutes');

        $action = AttachFilesAction::make('addAttachments')->record($matter);

        $this->assertTrue($action->isVisible());
        $this->assertSame($matter->getKey(), $action->getOwner()->getKey());
        $this->assertSame(
            [Category::GENERAL, Category::COURT_DOCUMENT, Category::REPORT, Category::CORRESPONDENCE, Category::MINUTES_SIGNED, Category::CONTRACT],
            AttachmentFields::categories($action->getOwner()),
        );

        $file = AttachmentFields::save($matter, ['files' => [AttachmentFields::DIRECTORY.'/a/Minutes.pdf'], 'category' => 'minutes_signed'])->sole();

        $this->assertSame([Category::MINUTES_SIGNED, $matter->id, 'attachments'], [$file->category, $file->attachable_id, $file->disk]);
        $this->assertSame(1, $matter->ownedAttachments()->count());
        $this->assertSame(1, $matter->attachments()->count());
    }

    public function test_the_initial_report_keeps_its_screenshot_as_a_report_file_of_the_matter(): void
    {
        $this->allowEverything();
        $matter = Matter::factory()->create(['initial_report_at' => null]);

        Livewire::test(ViewMatter::class, ['record' => $matter->getRouteKey()])
            ->callAction('initial_report', data: ['screen_shot' => UploadedFile::fake()->image('shot.png')])
            ->assertHasNoActionErrors();

        $file = $matter->refresh()->ownedAttachments()->sole();
        $this->assertNotNull($matter->initial_report_at);
        $this->assertSame([Category::REPORT, 'attachments', 'shot.png'], [$file->category, $file->disk, $file->name]);
        Storage::disk('attachments')->assertExists($file->path);
    }

    public function test_files_in_memory_such_as_from_onedrive_are_kept_with_the_request_under_their_names(): void
    {
        $this->allowEverything();
        $request = $this->request();

        $attached = AttachmentFields::attach($request, [['name' => 'Judgment 3/2026.pdf', 'contents' => '%PDF-judgment']], Category::REQUEST);

        $file = $attached->sole();
        $this->assertSame([Category::REQUEST, 'Judgment 3 2026.pdf', 'user', $request->id], [$file->category, $file->name, $file->source, $request->attachments()->sole()->attachable_id]);
        $this->assertSame('%PDF-judgment', Storage::disk('attachments')->get($file->path));
    }

    public function test_a_new_request_keeps_the_files_it_was_made_with_as_request_files(): void
    {
        $this->allowEverything();
        Mail::fake();
        $matter = Matter::factory()->create();
        Storage::disk('local')->put(AttachmentFields::DIRECTORY.'/a/Report.pdf', '%PDF-report');

        CreateRequestAction::make()->record($matter)->data([
            'type' => RequestType::REVIEW_REPORT,
            'comment' => 'Please review',
            'attachments' => [AttachmentFields::DIRECTORY.'/a/Report.pdf'],
        ])->call(['component' => null]);

        $request = $matter->requests()->sole();
        $file = $request->attachments()->sole();
        $this->assertSame([Category::REQUEST, 'Report.pdf', 'attachments', $matter->id, auth()->id()], [$file->category, $file->name, $file->disk, $file->matter_id, $file->user_id]);
        $this->assertSame('%PDF-report', Storage::disk('attachments')->get($file->path));
    }

    public function test_a_file_already_stored_by_whatsapp_is_kept_as_its_source(): void
    {
        $request = $this->request();
        Storage::disk('public')->put('requests-attachments/abc/photo.jpg', 'JPEG');

        $file = AttachmentFields::attach($request, [['path' => 'requests-attachments/abc/photo.jpg', 'source' => 'whatsapp']], Category::REQUEST)->sole();

        $this->assertSame(['photo.jpg', 'whatsapp', 'attachments'], [$file->name, $file->source, $file->disk]);
        Storage::disk('public')->assertMissing('requests-attachments/abc/photo.jpg');
    }

    public function test_a_kind_of_file_or_a_size_that_is_not_allowed_is_refused_by_the_form_not_with_an_error(): void
    {
        $this->allowEverything();
        $fee = $this->fee();
        config(['attachments.max_kb' => 10]);

        $this->list($fee)
            ->callAction(TestAction::make('attachFiles')->table(), data: ['files' => [UploadedFile::fake()->create('run.exe', 1)], 'category' => Category::GENERAL->value])
            ->assertHasFormErrors(['files']);
        $this->list($fee)
            ->callAction(TestAction::make('attachFiles')->table(), data: ['files' => [$this->pdf('big.pdf', 50)], 'category' => Category::GENERAL->value])
            ->assertHasFormErrors(['files']);

        $this->assertSame(0, $fee->ownedAttachments()->count());
    }

    public function test_a_file_the_service_refuses_is_told_in_a_notification_and_the_others_still_attach(): void
    {
        $this->allowEverything();
        $fee = $this->fee();
        // Stored as an upload would have been, one with a kind that is not allowed.
        Storage::disk('local')->put(AttachmentFields::DIRECTORY.'/a/run.exe', 'MZ');
        Storage::disk('local')->put(AttachmentFields::DIRECTORY.'/b/ok.pdf', '%PDF');

        $attached = AttachmentFields::save($fee, ['files' => [AttachmentFields::DIRECTORY.'/a/run.exe', AttachmentFields::DIRECTORY.'/b/ok.pdf'], 'category' => 'general']);

        $this->assertSame(['ok.pdf'], $attached->pluck('name')->all());
        $notifications = session('filament.notifications') ?? [];
        $this->assertSame(['danger'], collect($notifications)->pluck('status')->unique()->values()->all());
        $this->assertStringContainsString('run.exe', json_encode($notifications));
        // Nothing left waiting.
        $this->assertSame([], Storage::disk('local')->allFiles(AttachmentFields::DIRECTORY));
    }

    public function test_a_path_that_is_not_an_upload_is_never_taken_in(): void
    {
        $this->allowEverything();
        $fee = $this->fee();
        Storage::disk('local')->put('secrets/key.pdf', 'private');

        $attached = AttachmentFields::save($fee, ['files' => ['secrets/key.pdf'], 'category' => 'general']);

        $this->assertTrue($attached->isEmpty());
        Storage::disk('local')->assertExists('secrets/key.pdf');
    }

    public function test_the_same_file_twice_is_warned_of_and_still_attached(): void
    {
        $this->allowEverything();
        $fee = $this->fee();
        app(AttachmentService::class)->store($fee, 'same bytes', Category::GENERAL, 'first.pdf');
        Storage::disk('local')->put(AttachmentFields::DIRECTORY.'/c/second.pdf', 'same bytes');

        $attached = AttachmentFields::save($fee, ['files' => [AttachmentFields::DIRECTORY.'/c/second.pdf'], 'category' => 'general']);

        $this->assertSame(['second.pdf'], $attached->pluck('name')->all());
        $this->assertSame(2, $fee->ownedAttachments()->count());
        $notifications = collect(session('filament.notifications') ?? []);
        $this->assertSame(['warning'], $notifications->pluck('status')->all());
        $this->assertStringContainsString('second.pdf', json_encode($notifications));
    }

    public function test_the_button_is_only_for_whoever_may_change_the_matter(): void
    {
        $fee = $this->fee();

        $this->actingAs(User::factory()->create());
        $this->assertFalse(AttachmentFields::canAttach($fee));
        // Nor does the list open for them.
        $this->assertFalse(AttachmentsRelationManager::canViewForRecord($fee, ViewMatter::class));
        $this->assertFalse(AttachFilesAction::make()->record($fee)->isVisible());
    }

    public function test_the_list_shows_live_files_filters_by_category_and_opens_them_through_the_controller(): void
    {
        $this->allowEverything();
        $fee = $this->fee();
        $service = app(AttachmentService::class);
        $invoice = $service->store($fee, '%PDF-1', Category::FEE_INVOICE, 'invoice.pdf', description: 'The invoice');
        $note = $service->store($fee, 'text', Category::GENERAL, 'note.txt');
        $hidden = $service->store($fee, '%PDF-2', Category::TAX_INVOICE, 'tax.pdf');
        $hidden->delete();

        $this->list($fee)
            ->assertCanSeeTableRecords([$invoice, $note])
            ->assertCanNotSeeTableRecords([$hidden])
            ->assertTableColumnStateSet('category', Category::FEE_INVOICE, $invoice)
            ->assertTableColumnFormattedStateSet('category', 'Fee invoice', $invoice)
            ->assertTableColumnFormattedStateSet('size_bytes', '6 B', $invoice)
            ->filterTable('category', Category::GENERAL->value)
            ->assertCanSeeTableRecords([$note])
            ->assertCanNotSeeTableRecords([$invoice])
            ->removeTableFilters()
            ->assertTableActionVisible('preview', $invoice)
            ->assertTableActionHidden('preview', $note)
            ->assertTableActionVisible('download', $note);

        $this->assertSame($invoice->url(), route('attachment.download', ['attachment' => $invoice->id]));
    }

    public function test_delete_follows_the_policy_and_keeps_financial_evidence(): void
    {
        $this->allowEverything();
        $fee = $this->fee();
        $service = app(AttachmentService::class);
        $evidence = $service->store($fee, '%PDF-1', Category::FEE_INVOICE, 'invoice.pdf');
        $general = $service->store($fee, 'text', Category::GENERAL, 'note.pdf');

        $this->list($fee)
            ->callAction(TestAction::make('delete')->table($evidence))
            ->assertNotified();
        $this->list($fee)
            ->callAction(TestAction::make('delete')->table($general))
            ->assertNotified();

        // The invoice: hidden, kept with its file. The note: gone with its file.
        $this->assertSoftDeleted($evidence);
        Storage::disk('attachments')->assertExists($evidence->path);
        $this->assertDatabaseMissing('attachments', ['id' => $general->id]);
        Storage::disk('attachments')->assertMissing($general->path);
        $this->list($fee)->assertCanNotSeeTableRecords([$evidence, $general]);
    }

    public function test_delete_is_refused_to_someone_who_may_neither_change_the_matter_nor_made_the_file(): void
    {
        $fee = $this->fee();
        $file = app(AttachmentService::class)->store($fee, '%PDF-1', Category::GENERAL, 'note.pdf', by: User::factory()->create());

        $this->actingAs(User::factory()->create());
        $this->assertFalse(auth()->user()->can('delete', $file));

        // Whoever made it may.
        $this->actingAs($file->user);
        $this->assertTrue($file->user->can('delete', $file));
    }

    public function test_the_count_column_counts_only_live_files(): void
    {
        $this->allowEverything();
        $fee = $this->fee();
        $service = app(AttachmentService::class);
        $service->store($fee, 'a', Category::GENERAL, 'a.pdf');
        $service->store($fee, 'b', Category::FEE_INVOICE, 'b.pdf')->delete();

        $count = Fee::query()->withCount('ownedAttachments')->find($fee->id)->owned_attachments_count;
        $this->assertSame(1, $count);

        $column = AttachmentCount::column();
        $this->assertSame('owned_attachments_count', $column->getName());
    }

    private function request(RequestType $type = RequestType::REVIEW_REPORT): MatterRequest
    {
        return MatterRequest::create(['matter_id' => Matter::factory()->create()->id, 'request_by' => User::factory()->create()->id, 'type' => $type, 'status' => RequestStatus::PENDING, 'comment' => 'x']);
    }

    public function test_approving_a_request_keeps_its_uploads_as_request_files_on_the_private_disk(): void
    {
        $this->allowEverything();
        $request = $this->request();

        Livewire::test(ViewMatterRequest::class, ['record' => $request->id])
            ->callAction(ApproveRequestAction::class, data: ['approved_comment' => 'ok', 'has_substantive_changes' => false, 'attachments' => [$this->pdf('Reviewed.pdf')]])
            ->assertHasNoActionErrors();

        $file = $request->refresh()->attachments()->sole();
        $this->assertSame([RequestStatus::APPROVED, MatterRequest::class, $request->id, Category::REQUEST, 'attachments', 'user', 'Reviewed.pdf'], [$request->status, $file->attachable_type, $file->attachable_id, $file->category, $file->disk, $file->source, $file->name]);
        Storage::disk('attachments')->assertExists($file->path);
        $this->assertSame($request->matter_id, $file->matter_id);
    }

    public function test_rejecting_a_request_that_needs_files_asks_for_them(): void
    {
        $this->allowEverything();
        $request = $this->request(RequestType::CONFIRM_REPORT);

        Livewire::test(ViewMatterRequest::class, ['record' => $request->id])
            ->callAction(RejectRequestAction::class, data: ['approved_comment' => 'no', 'attachments' => []])
            ->assertHasActionErrors(['attachments']);
        $this->assertSame(RequestStatus::PENDING, $request->refresh()->status);

        Livewire::test(ViewMatterRequest::class, ['record' => $request->id])
            ->callAction(RejectRequestAction::class, data: ['approved_comment' => 'no', 'attachments' => [$this->pdf('Why.pdf')]])
            ->assertHasNoActionErrors();
        $this->assertSame(RequestStatus::REJECTED, $request->refresh()->status);
        $this->assertSame(['Why.pdf'], $request->attachments()->pluck('name')->all());
    }
}
