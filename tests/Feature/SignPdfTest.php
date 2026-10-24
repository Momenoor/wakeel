<?php

namespace Tests\Feature;

use App\Enums\AttachmentCategory;
use App\Enums\SignatureStampKind;
use App\Filament\Mms\Pages\SignPdf;
use App\Filament\Mms\RelationManagers\AttachmentsRelationManager;
use App\Filament\Mms\Resources\Matters\Pages\ViewMatter;
use App\Filament\Support\SignPdfAction;
use App\Models\Attachment;
use App\Models\Fee;
use App\Models\Matter;
use App\Models\SignatureStamp;
use App\Models\User;
use App\Services\Attachments\AttachmentService;
use App\Services\SignatureStamps\SigningLog;
use App\Support\ScreenPermissions;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Signing a PDF: the Sign PDF page for those allowed, which keeps nothing of a PDF signed on its own but a
 * line in the activity log; "Sign & stamp" on a PDF attachment, whose signed copy becomes a new attachment
 * beside the original — never in its place.
 */
class SignPdfTest extends TestCase
{
    use RefreshDatabase;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private const SIGNER = [ScreenPermissions::SIGN_PDF, 'View:Matter', 'Update:Matter'];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');
        Filament::setCurrentPanel('mms');
    }

    /** @param  list<string>  $permissions */
    private function userWith(array $permissions = self::SIGNER): User
    {
        $user = User::factory()->create();

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $user->givePermissionTo($permissions);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }

    private function attachment(string $name = 'Invoice.pdf', string $contents = '%PDF-1.7 the original', AttachmentCategory $category = AttachmentCategory::FEE_INVOICE): Attachment
    {
        $fee = Fee::factory()->create(['matter_id' => Matter::factory()->create()->id]);

        return app(AttachmentService::class)->store($fee, $contents, $category, $name, User::factory()->create());
    }

    private function stamp(User $owner): SignatureStamp
    {
        return SignatureStamp::create([
            'name' => 'Mine', 'kind' => SignatureStampKind::SIGNATURE, 'user_id' => $owner->getKey(), 'is_shared' => false,
            'path' => SignatureStamp::storeImage(base64_decode(self::PNG)), 'disk' => SignatureStamp::disk(), 'width_mm' => 40,
        ]);
    }

    private function signed(string $contents = '%PDF-1.7 signed'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('Invoice - signed.pdf', $contents);
    }

    public function test_the_page_opens_only_for_those_who_may_sign(): void
    {
        $this->actingAs($this->userWith([]));
        $this->get(SignPdf::getUrl())->assertForbidden();

        $this->actingAs($me = $this->userWith([ScreenPermissions::SIGN_PDF]));
        $mine = $this->stamp($me);

        $this->get(SignPdf::getUrl())->assertOk()->assertSee('pdfSigner', false)
            // Every button's code as written for the browser: a Blade directive left in one ("@js(…)") was a
            // script error, and the Text button did nothing.
            ->assertDontSee('@js(', false)
            ->assertSee('textTemplate(labels.text)', false)
            // The PDF's own text, to be changed.
            ->assertSee(__('Edit the PDF\'s text'));

        $config = $this->editor();
        $this->assertSame(__('Text'), $config['labels']['text']);
        $this->assertSame([$mine->toEditor()], $config['stamps']);
        // On its own nothing is saved on the server: no PDF to fetch, no address to send it to.
        $this->assertNull($config['pdfUrl']);
        $this->assertNull($config['saveUrl']);
    }

    /**
     * What the editor is given, as the page would give it.
     *
     * @return array<string, mixed>
     */
    private function editor(?Attachment $attachment = null): array
    {
        $page = new SignPdf;
        $page->attachmentId = $attachment?->getKey();

        return $page->editorConfig();
    }

    public function test_a_pdf_signed_on_its_own_leaves_only_a_line_in_the_activity_log(): void
    {
        $this->actingAs($me = $this->userWith([ScreenPermissions::SIGN_PDF]));
        $mine = $this->stamp($me);
        $someoneElses = $this->stamp(User::factory()->create());
        $filesBefore = Storage::disk('attachments')->allFiles();

        Livewire::test(SignPdf::class)->call('recordSigning', 'Contract.pdf', 3, [$mine->getKey(), $someoneElses->getKey(), 'x']);

        $log = Activity::query()->where('log_name', SigningLog::LOG)->sole();
        $this->assertSame($me->getKey(), (int) $log->causer_id);
        $this->assertSame('signed', $log->event);
        $this->assertSame('Contract.pdf', $log->getProperty('file'));
        $this->assertSame(3, $log->getProperty('pages'));
        // Only what they could have used is named.
        $this->assertSame([$mine->getKey()], array_column($log->getProperty('items'), 'id'));
        $this->assertNull($log->subject_id);

        // No file anywhere.
        $this->assertSame($filesBefore, Storage::disk('attachments')->allFiles());
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame(0, Attachment::query()->count());
    }

    public function test_a_drawing_can_be_kept_in_the_library_from_the_editor(): void
    {
        $this->actingAs($me = $this->userWith([ScreenPermissions::SIGN_PDF]));

        $given = Livewire::test(SignPdf::class)->instance()->saveDrawn('data:image/png;base64,'.self::PNG, 'Quick', 'stamp');

        $stamp = SignatureStamp::query()->sole();
        $this->assertSame([$me->getKey(), SignatureStampKind::STAMP, 'Quick', false], [$stamp->user_id, $stamp->kind, $stamp->name, $stamp->is_shared]);
        Storage::disk('attachments')->assertExists($stamp->path);
        $this->assertSame($stamp->toEditor(), $given);

        $this->assertNull(Livewire::test(SignPdf::class)->instance()->saveDrawn('data:image/png;base64,'.base64_encode('not a png'), 'Bad', 'signature'));
        $this->assertSame(1, SignatureStamp::query()->count());
    }

    public function test_sign_and_stamp_is_on_pdf_attachments_for_those_who_may_open_them_and_sign(): void
    {
        $pdf = $this->attachment();
        $word = $this->attachment('Letter.docx', 'PK word');

        $this->actingAs($this->userWith([ScreenPermissions::SIGN_PDF, 'View:Matter']));
        $this->assertTrue(SignPdfAction::make()->record($pdf)->isVisible());
        $this->assertStringEndsWith('?attachment='.$pdf->getKey(), SignPdfAction::make()->record($pdf)->getUrl());
        $this->assertFalse(SignPdfAction::make()->record($word)->isVisible());

        // On the attachments list, row by row.
        Livewire::test(AttachmentsRelationManager::class, ['ownerRecord' => $pdf->attachable, 'pageClass' => ViewMatter::class])
            ->assertActionVisible(TestAction::make('signPdf')->table($pdf));

        // Without the signing permission, or without the matter.
        $this->actingAs($this->userWith(['View:Matter']));
        $this->assertFalse(SignPdfAction::make()->record($pdf)->isVisible());

        $this->actingAs($this->userWith([ScreenPermissions::SIGN_PDF]));
        $this->assertFalse(SignPdfAction::make()->record($pdf)->isVisible());
    }

    public function test_the_page_opens_on_an_attachment_only_when_it_may_be_signed(): void
    {
        $pdf = $this->attachment();
        $word = $this->attachment('Letter.docx', 'PK word');

        $this->actingAs($this->userWith());
        $this->get(SignPdf::getUrl(['attachment' => $pdf->getKey()]))->assertOk();
        $config = $this->editor($pdf);
        $this->assertSame([$pdf->url(inline: true), route('attachment.signed', $pdf), 'Invoice.pdf'], [$config['pdfUrl'], $config['saveUrl'], $config['fileName']]);
        $this->get(SignPdf::getUrl(['attachment' => $word->getKey()]))->assertForbidden();
        $this->get(SignPdf::getUrl(['attachment' => 999999]))->assertNotFound();

        // One who may open it but not add to its matter signs it and downloads it: no saving.
        $this->actingAs($this->userWith([ScreenPermissions::SIGN_PDF, 'View:Matter']));
        $this->get(SignPdf::getUrl(['attachment' => $pdf->getKey()]))->assertOk();
        $this->assertNull($this->editor($pdf)['saveUrl']);

        $this->actingAs($this->userWith([ScreenPermissions::SIGN_PDF]));
        $this->get(SignPdf::getUrl(['attachment' => $pdf->getKey()]))->assertForbidden();
    }

    public function test_the_signed_copy_becomes_a_new_attachment_beside_the_original(): void
    {
        $original = $this->attachment();
        $before = $original->only(['path', 'checksum', 'name', 'category', 'description']);
        $this->actingAs($me = $this->userWith());
        $mine = $this->stamp($me);

        $this->post(route('attachment.signed', $original), ['file' => $this->signed('%PDF-1.7 signed bytes'), 'pages' => 2, 'items' => [$mine->getKey()]], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('name', 'Invoice - signed.pdf');

        $copy = Attachment::query()->whereKeyNot($original->getKey())->sole();
        $this->assertSame(
            [Fee::class, $original->attachable_id, $original->matter_id, AttachmentCategory::FEE_INVOICE, 'attachments', 'Invoice - signed.pdf', 'Signed copy of #'.$original->getKey(), 'user', $me->getKey()],
            [$copy->attachable_type, $copy->attachable_id, $copy->matter_id, $copy->category, $copy->disk, $copy->name, $copy->description, $copy->source, $copy->user_id],
        );
        $this->assertSame('%PDF-1.7 signed bytes', Storage::disk('attachments')->get($copy->path));

        // The original, untouched.
        $this->assertEquals($before, $original->fresh()->only(['path', 'checksum', 'name', 'category', 'description']));
        $this->assertSame('%PDF-1.7 the original', Storage::disk('attachments')->get($original->path));

        $log = Activity::query()->where('log_name', SigningLog::LOG)->sole();
        $this->assertSame([$copy->getKey(), 2, [$mine->getKey()]], [(int) $log->subject_id, $log->getProperty('pages'), array_column($log->getProperty('items'), 'id')]);
    }

    public function test_a_category_the_record_no_longer_allows_falls_back_to_general(): void
    {
        $original = $this->attachment();
        $original->update(['category' => AttachmentCategory::REPORT]);
        $this->actingAs($this->userWith());

        $this->post(route('attachment.signed', $original), ['file' => $this->signed()], ['Accept' => 'application/json'])->assertCreated();

        $this->assertSame(AttachmentCategory::GENERAL, Attachment::query()->whereKeyNot($original->getKey())->sole()->category);
    }

    public function test_saving_a_signed_copy_is_refused_to_those_who_may_not_and_for_what_is_not_a_pdf(): void
    {
        $original = $this->attachment();
        $word = $this->attachment('Letter.docx', 'PK word');
        $post = fn (Attachment $attachment, ?UploadedFile $file = null) => $this->post(route('attachment.signed', $attachment), ['file' => $file ?? $this->signed()], ['Accept' => 'application/json']);

        $this->actingAs($this->userWith(['View:Matter', 'Update:Matter']));
        $post($original)->assertForbidden();

        $this->actingAs($this->userWith([ScreenPermissions::SIGN_PDF, 'View:Matter']));
        $post($original)->assertForbidden();

        $this->actingAs($this->userWith([ScreenPermissions::SIGN_PDF, 'Update:Matter']));
        $post($original)->assertForbidden();

        $this->actingAs($this->userWith());
        $post($word)->assertForbidden();
        $post($original, UploadedFile::fake()->createWithContent('Invoice - signed.pdf', '<html>not a pdf</html>'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');

        $this->assertSame(2, Attachment::query()->count());
    }
}
