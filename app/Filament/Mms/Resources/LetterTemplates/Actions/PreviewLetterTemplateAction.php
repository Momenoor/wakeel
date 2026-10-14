<?php

namespace App\Filament\Mms\Resources\LetterTemplates\Actions;

use App\Enums\LetterTemplateCategories;
use App\Models\LetterItem;
use App\Models\LetterTemplate;
use App\Models\Matter;
use App\Models\MatterParty;
use App\Services\MMS\Letters\LetterComposer;
use App\Services\MMS\Letters\LetterPdf;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The template as a PDF for a real matter, before issuing anything: the
 * matter's details and parties filled in, the template's own fields with
 * sample values (their label, today's date, the first library items) — and,
 * for minutes, a sample meeting: who attended (the claimant's side) and who
 * did not (the others), so a form's check boxes and lists show filled in.
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

        if (($template->category instanceof LetterTemplateCategories ? $template->category : LetterTemplateCategories::tryFrom((string) $template->category)) === LetterTemplateCategories::MINUTES) {
            $inputs[LetterComposer::MEETING_START] = now()->setTime(10, 30)->toDateTimeString();
            $inputs[LetterComposer::MINUTES] = [
                'number' => 1,
                'attendees' => self::sampleAttendees($matter, $template->locale !== 'en'),
                'items' => [],
            ];
        }

        return $inputs;
    }

    /**
     * The matter's own people at a sample meeting: those on the plaintiffs'
     * side attended, the others did not — and an interpreter was there.
     *
     * @return list<array<string, mixed>>
     */
    private static function sampleAttendees(Matter $matter, bool $arabic): array
    {
        $matter->loadMissing('matterParties');
        $plaintiffs = $matter->matterParties
            ->filter(fn (MatterParty $mp) => empty($mp->parent_id) && $mp->role === 'party' && $mp->type === 'plaintiff')
            ->pluck('id');

        $attendees = collect(LetterComposer::candidates($matter, $arabic))
            ->map(function (array $candidate, int|string $rowId) use ($matter, $plaintiffs): array {
                // A main party's own row, or a representative's under it.
                $mainRowId = (int) ($candidate['of'] ?? $rowId);
                $main = $matter->matterParties->firstWhere('id', $mainRowId);

                return [
                    'present' => $plaintiffs->contains($mainRowId),
                    'title' => '',
                    'name' => (string) ($candidate['name'] ?? ''),
                    'capacity' => (string) ($candidate['role'] ?? ''),
                    'party_id' => $candidate['party_id'] ?? null,
                    'represents' => isset($candidate['of']) ? $main?->party_id : null,
                ];
            })
            ->values();

        return [
            ...$attendees->all(),
            ['present' => true, 'title' => '', 'name' => $arabic ? 'مترجم (نموذج)' : 'Interpreter (sample)', 'capacity' => $arabic ? 'مترجم' : 'Interpreter'],
        ];
    }
}
