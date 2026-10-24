<?php

namespace App\Filament\Mms\Pages;

use App\Enums\SignatureStampKind;
use App\Filament\Forms\Components\SignaturePad;
use App\Filament\Mms\Resources\Matters\MatterResource;
use App\Filament\Mms\Resources\SignatureStamps\SignatureStampResource;
use App\Filament\Support\AttachmentFields;
use App\Filament\Support\SignPdfAction;
use App\Models\Attachment;
use App\Models\Contracts\AttachmentOwner;
use App\Models\SignatureStamp;
use App\Services\SignatureStamps\SigningLog;
use App\Support\Dates;
use App\Support\ScreenPermissions;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;

/**
 * Signing and stamping a PDF in the browser (resources/js/pdf-signer.js): the pages shown one under the
 * other, the signer's signatures and stamps (their own and the office's) put on them, then written into the
 * PDF there — never on the server.
 *
 *  - On its own: a PDF from the signer's computer, downloaded signed. Only a line in the activity log says
 *    it happened (who, the file's name, its pages, which signatures); the PDF is never sent.
 *  - With ?attachment=<id> (SignPdfAction): an attachment's PDF, whose signed copy is kept as a new
 *    attachment beside it (SignedAttachmentController) — the original is never changed.
 */
class SignPdf extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPencilSquare;

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.mms.pages.sign-pdf';

    protected Width|string|null $maxContentWidth = Width::Full;

    /** The attachment being signed, when opened from one. */
    #[Locked]
    public ?int $attachmentId = null;

    /** Where saving the signed copy goes back to: the page the signer came from. */
    #[Locked]
    public ?string $returnUrl = null;

    public static function canAccess(): bool
    {
        return auth()->user()?->can(ScreenPermissions::SIGN_PDF) ?? false;
    }

    public static function getNavigationLabel(): string
    {
        return __('Sign PDF');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('PDF tools');
    }

    public function getTitle(): string
    {
        return ($attachment = $this->attachment()) ? __('Sign & stamp: :name', ['name' => $attachment->name]) : __('Sign PDF');
    }

    public function mount(): void
    {
        $id = request()->query('attachment');

        if (filled($id)) {
            $attachment = Attachment::query()->find((int) $id);
            abort_if($attachment === null, 404);
            abort_unless(SignPdfAction::canSign($attachment), 403);

            $this->attachmentId = $attachment->getKey();
            $this->returnUrl = $this->backTo($attachment, request()->headers->get('referer'));
        }
    }

    public function attachment(): ?Attachment
    {
        return $this->attachmentId ? Attachment::query()->find($this->attachmentId) : null;
    }

    /**
     * What the editor is given: the PDF (for an attachment), where its signed copy goes, the signer's
     * signatures and stamps, and its words in the signer's language.
     *
     * @return array<string, mixed>
     */
    public function editorConfig(): array
    {
        $attachment = $this->attachment();
        $owner = $attachment?->attachable;

        return [
            'pdfUrl' => $attachment?->url(inline: true),
            'fileName' => $attachment?->name,
            // Only for whoever may add files to its record: the others may still sign and download it.
            'saveUrl' => $attachment && $owner instanceof AttachmentOwner && AttachmentFields::canAttach($owner) ? route('attachment.signed', $attachment) : null,
            'returnUrl' => $this->returnUrl,
            'csrf' => csrf_token(),
            'maxBytes' => (int) config('attachments.max_kb') * 1024,
            'stamps' => SignatureStamp::query()->usableBy(auth()->user())->orderByDesc('is_shared')->orderBy('name')->get()->map->toEditor()->all(),
            'widths' => collect(SignatureStampKind::cases())->mapWithKeys(fn (SignatureStampKind $kind): array => [$kind->value => $kind->defaultWidthMm()])->all(),
            'canSaveDrawn' => auth()->user()->can('create', SignatureStamp::class),
            'today' => now()->format(Dates::dateFormat()),
            'labels' => [
                'tooBig' => __('The file is bigger than :size MB.', ['size' => round((int) config('attachments.max_kb') / 1024)]),
                'notPdf' => __('This is not a PDF file.'),
                'loadFailed' => __('The PDF could not be opened.'),
                'protected' => __('This PDF is protected with a password, so it cannot be signed here.'),
                'saveFailed' => __('The signed PDF could not be made. Please try again.'),
                'imageFailed' => __('The image could not be loaded.'),
                'nothingPlaced' => __('Put a signature, a stamp or text on the document first.'),
                'drawing' => __('My drawing'),
                // A new text box's words (a Blade component's attribute takes no @js: the button had "@js(…)" as its code).
                'text' => __('Text'),
            ],
        ];
    }

    public function libraryUrl(): ?string
    {
        return SignatureStampResource::canViewAny() ? SignatureStampResource::getUrl(panel: 'mms') : null;
    }

    /**
     * A PDF signed on its own leaves only this behind: who, which file (by name), how many pages, which of
     * their signatures — never the document.
     *
     * @param  array<int, mixed>  $items
     */
    public function recordSigning(string $fileName, int $pages, array $items = []): void
    {
        SigningLog::record(auth()->user(), Str::limit($fileName, 250, ''), $pages, $items, $this->attachment());
    }

    /**
     * A drawing kept in the signer's library from "Draw now"; what the editor needs of it.
     *
     * @return array<string, mixed>|null
     */
    public function saveDrawn(string $dataUrl, string $name, string $kind): ?array
    {
        $user = auth()->user();
        $png = SignaturePad::decode($dataUrl);
        $kind = SignatureStampKind::tryFrom($kind) ?? SignatureStampKind::SIGNATURE;

        if (! $user->can('create', SignatureStamp::class) || $png === null) {
            Notification::make()->danger()->title(__('The drawing could not be saved.'))->send();

            return null;
        }

        $stamp = SignatureStamp::create([
            'name' => Str::limit(trim($name) ?: __('My drawing'), 100, ''),
            'kind' => $kind,
            'user_id' => $user->getKey(),
            'is_shared' => false,
            'path' => SignatureStamp::storeImage($png),
            'disk' => SignatureStamp::disk(),
            'width_mm' => $kind->defaultWidthMm(),
        ]);

        Notification::make()->success()->title(__('Saved to your signatures and stamps.'))->send();

        return $stamp->toEditor();
    }

    /** The page the signer came from when it is one of ours, else the attachment's matter (or the dashboard). */
    private function backTo(Attachment $attachment, ?string $referer): string
    {
        $self = static::getUrl(panel: 'mms');

        if (filled($referer) && str_starts_with($referer, url('/').'/') && ! str_starts_with($referer, $self)) {
            return $referer;
        }

        $matter = $attachment->matter;

        return $matter && MatterResource::canView($matter)
            ? MatterResource::getUrl('view', ['record' => $matter], panel: 'mms')
            : AdminDashboard::getUrl(panel: 'mms');
    }
}
