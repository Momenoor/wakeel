<?php

namespace App\Filament\Mms\Resources\Letterheads\Pages;

use App\Filament\Mms\Resources\Letterheads\LetterheadResource;
use App\Models\Letterhead;
use App\Models\LetterTemplate;
use App\Models\Matter;
use App\Services\MMS\Letters\LetterComposer;
use App\Services\MMS\Letters\LetterPdf;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Livewire\WithFileUploads;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Drag-and-drop placement of a letterhead's elements on its first page:
 * pick an element, drag it where it belongs, fine-tune it in the side
 * panel. Positions are millimetres from the page's top-left corner — the
 * same numbers the PDF and Word output use.
 *
 * @property Letterhead $record
 */
class DesignLetterhead extends Page
{
    use InteractsWithRecord;
    use WithFileUploads;

    protected static string $resource = LetterheadResource::class;

    protected string $view = 'filament.mms.letterheads.design';

    /** @var list<array<string, mixed>> */
    public array $elements = [];

    public ?int $selected = null;

    /** @var mixed an image being uploaded for the selected image element */
    public $imageUpload = null;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        $this->elements = array_values($this->record->elements ?? []);
    }

    public function getTitle(): string
    {
        return __('Design letterhead').' — '.$this->record->name;
    }

    public function addElement(string $type): void
    {
        if (! in_array($type, Letterhead::ELEMENT_TYPES, true)) {
            return;
        }

        $this->elements[] = [
            'type' => $type,
            'page' => $type === 'page_number' ? 'all' : 'first',
            'x' => 20,
            'y' => 20,
            'width' => match ($type) {
                'logo', 'image' => 40,
                'line' => 170,
                'page_number' => 20,
                default => 80,
            },
            'content' => $type === 'text' ? __('Text') : null,
            'font_size' => $type === 'line' ? 3 : 11,
            'bold' => false,
            'align' => 'left',
            'color' => '#111827',
        ];

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

        [$width, $height] = $this->pageSize();
        $this->elements[$index]['x'] = round(max(0, min($width - 5, $x)), 1);
        $this->elements[$index]['y'] = round(max(0, min($height - 3, $y)), 1);
        $this->selected = $index;
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

        $this->elements[$this->selected]['content'] = $this->imageUpload->store(Letterhead::DIRECTORY, Letterhead::DISK);
        $this->imageUpload = null;
    }

    public function save(): void
    {
        $this->record->update(['elements' => array_values($this->elements)]);

        Notification::make()->success()->title(__('Saved'))->send();
    }

    /**
     * @return array{0: int, 1: int} page width and height in mm
     */
    public function pageSize(): array
    {
        return $this->record->orientation === 'landscape'
            ? [Letterhead::PAGE_HEIGHT, Letterhead::PAGE_WIDTH]
            : [Letterhead::PAGE_WIDTH, Letterhead::PAGE_HEIGHT];
    }

    /**
     * What an element shows in the designer.
     *
     * @param  array<string, mixed>  $element
     */
    public function label(array $element): string
    {
        return match ($element['type'] ?? 'text') {
            'reference' => 'المرجع: JPA/2026/123/1',
            'date' => 'التاريخ: '.now()->format('d/m/Y'),
            'page_number' => '1 / 1',
            'logo' => __('Logo'),
            'image' => __('Image'),
            'line' => '',
            default => (string) ($element['content'] ?? ''),
        };
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
                ->label(__('Images & margins'))
                ->icon('heroicon-o-cog-6-tooth')
                ->color('gray')
                ->url(fn () => LetterheadResource::getUrl('edit', ['record' => $this->record])),
        ];
    }

    /**
     * A sample letter on this letterhead, with the elements as they are now
     * (saved or not).
     */
    public function preview(): StreamedResponse
    {
        $letterhead = $this->record->replicate();
        $letterhead->elements = array_values($this->elements);

        $template = new LetterTemplate([
            'locale' => 'ar',
            'subject' => 'نموذج خطاب',
            'body' => '<p>{{recipients}}</p><p>تحية طيبة وبعد،</p><p><strong>الموضوع: {{subject}}</strong></p>'
                .str_repeat('<p>هذا نص تجريبي لمعاينة ترويسة الخطاب وهوامشه وموضع عناصره على الصفحة، ويتكرر لملء الصفحة ومشاهدة الصفحات التالية.</p>', 18)
                .'<p>وتفضلوا بقبول وافر الاحترام والتقدير،</p><p>{{signature}}</p>',
        ]);

        $matter = new Matter(['number' => '123', 'year' => now()->year]);
        $composer = new LetterComposer($template, $matter, [], [['name' => 'شركة المثال', 'role' => 'المدعي', 'emails' => ['info@example.com']]], 'JPA/'.now()->year.'/123/1', now(), $letterhead);
        $pdf = (new LetterPdf($composer))->render();

        return response()->streamDownload(fn () => print ($pdf), 'letterhead-preview.pdf', ['Content-Type' => 'application/pdf']);
    }
}
