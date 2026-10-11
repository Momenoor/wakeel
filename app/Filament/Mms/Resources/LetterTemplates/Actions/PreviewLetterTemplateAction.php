<?php

namespace App\Filament\Mms\Resources\LetterTemplates\Actions;

use App\Models\LetterItem;
use App\Models\LetterTemplate;
use App\Models\Matter;
use App\Services\MMS\Letters\LetterComposer;
use App\Services\MMS\Letters\LetterPdf;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The template as a PDF for a real matter, before issuing anything: the
 * matter's details and parties filled in, the template's own fields with
 * sample values (their label, today's date, the first library items).
 */
class PreviewLetterTemplateAction
{
    public static function make(): Action
    {
        return Action::make('previewPdf')
            ->label(__('Preview PDF'))
            ->icon('heroicon-o-eye')
            ->color('gray')
            ->modalWidth('xl')
            ->modalSubmitActionLabel(__('Preview'))
            ->schema([
                Select::make('matter_id')
                    ->label(__('Matter'))
                    ->options(fn () => Matter::query()->latest('id')->limit(50)->get()->mapWithKeys(fn (Matter $m) => [$m->id => $m->reference]))
                    ->getSearchResultsUsing(fn (string $search) => Matter::query()
                        ->where(fn ($q) => $q->where('number', 'like', "%{$search}%")->orWhere('year', 'like', "%{$search}%"))
                        ->limit(50)->get()->mapWithKeys(fn (Matter $m) => [$m->id => $m->reference]))
                    ->searchable()
                    ->required(),
            ])
            ->action(fn (LetterTemplate $record, array $data) => self::download($record, Matter::findOrFail($data['matter_id'])));
    }

    public static function download(LetterTemplate $template, Matter $matter): StreamedResponse
    {
        $composer = new LetterComposer(
            $template,
            $matter,
            self::sampleInputs($template, $matter),
            array_values(LetterComposer::candidates($matter, $template->locale !== 'en')),
            'JPA/'.$matter->year.'/'.$matter->number.'/—',
            now(),
        );

        $pdf = (new LetterPdf($composer))->render();

        return response()->streamDownload(fn () => print ($pdf), 'preview.pdf', ['Content-Type' => 'application/pdf']);
    }

    /**
     * @return array<string, mixed>
     */
    public static function sampleInputs(LetterTemplate $template, Matter $matter): array
    {
        $inputs = [];

        foreach ($template->inputs ?? [] as $input) {
            $key = $input['key'] ?? null;
            if (blank($key)) {
                continue;
            }

            $inputs[$key] = match ($input['type'] ?? 'text') {
                'date' => now()->toDateString(),
                'time' => '10:00',
                'url' => 'https://teams.microsoft.com/…',
                'number' => '1',
                'select' => $input['options'][0] ?? '',
                'items' => LetterItem::query()->forGroup($input['group'] ?? null, $matter->type_id)->limit(5)->pluck('id')->all(),
                default => '['.($input['label'] ?? $key).']',
            };
        }

        return $inputs;
    }
}
