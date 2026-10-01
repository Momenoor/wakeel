<?php

namespace App\Filament\Mms\Resources\SignatureLayouts\Pages;

use App\Filament\Mms\Resources\SignatureLayouts\SignatureLayoutResource;
use App\Models\Letterhead;
use App\Models\LetterTemplate;
use App\Models\Matter;
use App\Models\SignatureLayout;
use App\Services\MMS\Letters\Blocks\SavedSignatureBlock;
use App\Services\MMS\Letters\LetterComposer;
use App\Services\MMS\Letters\LetterPdf;
use App\Services\MMS\Letters\SignatureLayouts;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Livewire\WithFileUploads;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Laying out a signature block: drag the signature, the stamp, images and
 * lines of text where they go in the box — over each other as needed —
 * and stack them (the list's order: later on top; text always over the
 * images). Positions are millimetres from the box's top-left corner.
 *
 * @property SignatureLayout $record
 */
class DesignSignatureLayout extends Page
{
    use InteractsWithRecord;
    use WithFileUploads;

    protected static string $resource = SignatureLayoutResource::class;

    protected string $view = 'filament.mms.signature-layouts.design';

    /** @var list<array<string, mixed>> */
    public array $elements = [];

    public ?int $selected = null;

    /** The letterhead whose signature and stamp are shown. */
    public ?int $letterheadId = null;

    /** @var mixed an image being uploaded for the selected image element */
    public $imageUpload = null;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        $this->elements = array_values($this->record->elements ?? []);
        $this->letterheadId = Letterhead::default()?->getKey() ?? Letterhead::query()->value('id');
    }

    public function getTitle(): string
    {
        return __('Design signature block').' — '.$this->record->name;
    }

    public function addElement(string $type): void
    {
        if (! in_array($type, SignatureLayout::ELEMENT_TYPES, true)) {
            return;
        }

        $this->elements[] = $type === 'text'
            ? ['type' => 'text', 'x' => 0, 'y' => 0, 'width' => (float) $this->record->width, 'content' => __('Text'), 'font_size' => 12, 'bold' => false, 'align' => 'center', 'color' => '#111827']
            : ['type' => $type, 'x' => 5, 'y' => 5, 'height' => $type === 'stamp' ? 30 : 22, 'content' => null];

        $this->selected = array_key_last($this->elements);
    }

    public function select(int $index): void
    {
        $this->selected = isset($this->elements[$index]) ? $index : null;
        $this->imageUpload = null;
    }

    public function moveElement(int $index, float $x, float $y): void
    {
        if (! isset($this->elements[$index])) {
            return;
        }

        $this->elements[$index]['x'] = round(max(-20, min((float) $this->record->width - 2, $x)), 1);
        $this->elements[$index]['y'] = round(max(-20, min((float) $this->record->height - 2, $y)), 1);
        $this->selected = $index;
    }

    /**
     * Up the stack (drawn over the next one) or down it.
     */
    public function layer(int $index, int $direction): void
    {
        $other = $index + ($direction > 0 ? 1 : -1);

        if (! isset($this->elements[$index], $this->elements[$other])) {
            return;
        }

        [$this->elements[$index], $this->elements[$other]] = [$this->elements[$other], $this->elements[$index]];
        $this->selected = $other;
    }

    public function removeElement(int $index): void
    {
        unset($this->elements[$index]);
        $this->elements = array_values($this->elements);
        $this->selected = null;
    }

    public function updatedImageUpload(): void
    {
        if ($this->selected === null || ! $this->imageUpload) {
            return;
        }

        $this->validate(['imageUpload' => ['image', 'max:5120']]);

        $this->elements[$this->selected]['content'] = $this->imageUpload->store(SignatureLayout::DIRECTORY, SignatureLayout::DISK);
        $this->imageUpload = null;
    }

    public function save(): void
    {
        $this->record->update(['elements' => array_values($this->elements)]);

        Notification::make()->success()->title(__('Saved'))->send();
    }

    public function letterhead(): Letterhead
    {
        return Letterhead::find($this->letterheadId) ?? Letterhead::default() ?? Letterhead::fallback();
    }

    /**
     * The URL of what an image element shows in the designer.
     *
     * @param  array<string, mixed>  $element
     */
    public function imageUrl(array $element): ?string
    {
        $letterhead = $this->letterhead();

        return match ($element['type'] ?? null) {
            'signature' => $letterhead->signature_image ? $letterhead->url($letterhead->signature_image) : null,
            'stamp' => $letterhead->stamp_image ? $letterhead->url($letterhead->stamp_image) : null,
            'image' => SignatureLayout::url($element['content'] ?? null),
            default => null,
        };
    }

    public function typeLabel(string $type): string
    {
        return SignatureLayouts::typeLabel($type);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')
                ->label(__('Save'))
                ->action(fn () => $this->save()),
            Action::make('preview')
                ->label(__('Preview PDF'))
                ->icon('heroicon-o-eye')
                ->color('gray')
                ->action(fn () => $this->preview()),
            Action::make('settings')
                ->label(__('Name & size'))
                ->icon('heroicon-o-cog-6-tooth')
                ->color('gray')
                ->url(fn () => SignatureLayoutResource::getUrl('edit', ['record' => $this->record])),
        ];
    }

    /**
     * A sample letter ending with this block as it is now (saved or not),
     * on the chosen letterhead.
     */
    public function preview(): StreamedResponse
    {
        $snapshot = [...$this->record->snapshot(), 'elements' => array_values($this->elements)];
        $block = '<div data-type="customBlock" data-config="'.e(json_encode(['snapshot' => $snapshot], JSON_UNESCAPED_UNICODE)).'" data-id="'.SavedSignatureBlock::ID.'"></div>';

        $template = new LetterTemplate([
            'locale' => 'ar',
            'subject' => 'نموذج خطاب',
            'body' => '<p>{{recipients}}</p><p>تحية طيبة وبعد،</p><p><strong>الموضوع: {{subject}}</strong></p>'
                .'<p>هذا نص تجريبي لمعاينة كتلة التوقيع في آخر الخطاب.</p>'
                .'<p>وتفضلوا بقبول وافر الاحترام والتقدير،</p>'.$block,
        ]);

        $matter = new Matter(['number' => '123', 'year' => now()->year]);
        $composer = new LetterComposer($template, $matter, [], [['name' => 'شركة المثال', 'role' => 'المدعي', 'emails' => []]], 'JPA/'.now()->year.'/123/1', now(), $this->letterhead());
        $pdf = (new LetterPdf($composer))->render();

        return response()->streamDownload(fn () => print ($pdf), 'signature-block-preview.pdf', ['Content-Type' => 'application/pdf']);
    }
}
