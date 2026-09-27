<?php

namespace App\Filament\Mms\Resources\Matters\RelationManagers;

use App\Models\CalendarEvent;
use App\Models\Letterhead;
use App\Models\LetterItem;
use App\Models\LetterTemplate;
use App\Models\Matter;
use App\Models\MatterLetter;
use App\Services\MMS\Letters\LetterComposer;
use App\Services\MMS\Letters\LetterIssuer;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * A matter's letters, and issuing a new one: pick a template, tick who it
 * goes to, fill in the template's fields (items ticked from the library),
 * and it's numbered JPA/{year}/{number}/{n} and ready as PDF or Word.
 */
class LettersRelationManager extends RelationManager
{
    protected static string $relationship = 'letters';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Letters');
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('reference')
            ->columns([
                TextColumn::make('reference')->label(__('Reference'))->weight('bold')->searchable(),
                TextColumn::make('letter_date')->label(__('Date'))->date('d/m/Y')->sortable(),
                TextColumn::make('subject')->label(__('Subject'))->wrap()->searchable(),
                TextColumn::make('recipients.name')->label(__('To'))->listWithLineBreaks()->limitList(3)->expandableLimitedList(),
                TextColumn::make('template.name')->label(__('Template'))->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('sentBy.name')->label(__('By'))->toggleable(isToggledHiddenByDefault: true),
            ])
            ->headerActions([
                $this->issueAction(),
            ])
            ->recordActions([
                Action::make('pdf')
                    ->label('PDF')
                    ->icon('heroicon-o-document-arrow-down')
                    ->url(fn (MatterLetter $record) => route('letters.pdf', $record), shouldOpenInNewTab: true),
                Action::make('word')
                    ->label('Word')
                    ->icon('heroicon-o-document-text')
                    ->color('gray')
                    ->url(fn (MatterLetter $record) => route('letters.docx', $record)),
                ActionGroup::make([
                    DeleteAction::make(),
                ]),
            ]);
    }

    private function issueAction(): Action
    {
        return Action::make('issue')
            ->label(__('Issue letter'))
            ->icon('heroicon-o-pencil-square')
            ->modalWidth('5xl')
            ->modalSubmitActionLabel(__('Issue'))
            ->schema(fn () => $this->issueForm($this->getOwnerRecord()))
            ->action(function (array $data) {
                $letter = $this->issue($this->getOwnerRecord(), $data);

                Notification::make()
                    ->success()
                    ->title(__('Letter :reference issued', ['reference' => $letter->reference]))
                    ->actions([
                        Action::make('pdf')->label('PDF')->url(route('letters.pdf', $letter), shouldOpenInNewTab: true),
                        Action::make('word')->label('Word')->url(route('letters.docx', $letter)),
                    ])
                    ->send();
            });
    }

    /**
     * @return list<mixed>
     */
    private function issueForm(Matter $matter): array
    {
        $candidates = LetterComposer::candidates($matter);

        return [
            Section::make()
                ->columns(3)
                ->schema([
                    Select::make('letter_template_id')
                        ->label(__('Template'))
                        ->options(fn () => LetterTemplate::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                        ->searchable()
                        ->required()
                        ->live()
                        ->afterStateUpdated(fn ($state, Set $set) => $this->prefill($matter, $state ? LetterTemplate::find($state) : null, $set))
                        ->columnSpan(2),
                    DatePicker::make('letter_date')
                        ->label(__('Letter date'))
                        ->default(now())
                        ->required(),
                    Select::make('letterhead_id')
                        ->label(__('Letterhead'))
                        ->options(fn () => Letterhead::query()->orderBy('name')->pluck('name', 'id'))
                        ->placeholder(__('The template\'s letterhead'))
                        ->columnSpan(3),
                ]),

            Section::make(__('Recipients'))
                ->schema([
                    CheckboxList::make('recipients')
                        ->label('')
                        ->options(collect($candidates)->map(fn ($c) => trim($c['name'].($c['role'] ? ' ('.$c['role'].')' : '')))->all())
                        ->descriptions(collect($candidates)->map(fn ($c) => implode(' · ', $c['emails']))->all())
                        ->bulkToggleable()
                        ->columns(2),
                    Repeater::make('extra_recipients')
                        ->label(__('Other recipients'))
                        ->addActionLabel(__('Add recipient'))
                        ->defaultItems(0)
                        ->columns(3)
                        ->schema([
                            TextInput::make('name')->label(__('Name'))->required(),
                            TextInput::make('role')->label(__('Capacity')),
                            TagsInput::make('emails')->label(__('Emails'))->nestedRecursiveRules(['email']),
                        ]),
                ]),

            Section::make(__('Letter details'))
                ->visible(fn (Get $get) => filled($get('letter_template_id')))
                ->schema(fn (Get $get) => $this->inputFields($matter, LetterTemplate::find($get('letter_template_id'))))
                ->columns(2),
        ];
    }

    /**
     * The template's own fields, as form components under inputs.*.
     *
     * @return list<mixed>
     */
    private function inputFields(Matter $matter, ?LetterTemplate $template): array
    {
        $fields = [];

        foreach ($template?->inputs ?? [] as $input) {
            $key = $input['key'] ?? null;
            if (blank($key)) {
                continue;
            }

            $name = 'inputs.'.$key;
            $label = $input['label'] ?? $key;
            $required = ! empty($input['required']);

            if (($input['type'] ?? 'text') === 'items') {
                $fields[] = Group::make([
                    CheckboxList::make($name)
                        ->label($label)
                        ->options(fn () => LetterItem::query()->forGroup($input['group'] ?? null, $matter->type_id)->pluck('text', 'id'))
                        ->bulkToggleable()
                        ->searchable()
                        ->required($required),
                    Textarea::make('extra.'.$key)
                        ->label(__('More lines for this list (one per line)'))
                        ->rows(2),
                ])->columnSpanFull();

                continue;
            }

            $fields[] = (match ($input['type'] ?? 'text') {
                'textarea' => Textarea::make($name)->rows(3)->columnSpanFull(),
                'date' => DatePicker::make($name),
                'time' => TimePicker::make($name)->seconds(false),
                'url' => TextInput::make($name)->url()->columnSpanFull(),
                'number' => TextInput::make($name)->numeric(),
                'select' => Select::make($name)->options(array_combine($input['options'] ?? [], $input['options'] ?? []) ?: []),
                default => TextInput::make($name),
            })->label($label)->required($required);
        }

        return $fields;
    }

    /**
     * Meeting fields filled from the matter's next calendar event: a field
     * whose key contains "meeting" gets its date, time or Teams link.
     */
    private function prefill(Matter $matter, ?LetterTemplate $template, Set $set): void
    {
        if (! $template) {
            return;
        }

        $event = CalendarEvent::query()
            ->where('matter_id', $matter->getKey())
            ->where('start_datetime', '>=', now())
            ->orderBy('start_datetime')
            ->first();

        if ($template->letterhead_id) {
            $set('letterhead_id', $template->letterhead_id);
        }

        foreach ($template->inputs ?? [] as $input) {
            $key = $input['key'] ?? '';
            if (! $event || ! str_contains($key, 'meeting') && ! str_contains($key, 'teams')) {
                continue;
            }

            match ($input['type'] ?? null) {
                'date' => $set('inputs.'.$key, $event->start_datetime?->toDateString()),
                'time' => $set('inputs.'.$key, $event->start_datetime?->format('H:i')),
                'url' => $set('inputs.'.$key, $event->online_meeting_url),
                default => null,
            };
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function issue(Matter $matter, array $data): MatterLetter
    {
        $template = LetterTemplate::findOrFail($data['letter_template_id']);
        $candidates = LetterComposer::candidates($matter);

        $recipients = [
            ...collect($data['recipients'] ?? [])->map(fn ($id) => $candidates[$id] ?? null)->filter()->values()->all(),
            ...collect($data['extra_recipients'] ?? [])->map(fn ($r) => [
                'name' => (string) $r['name'],
                'role' => $r['role'] ?? null,
                'emails' => array_values($r['emails'] ?? []),
            ])->all(),
        ];

        $inputs = $data['inputs'] ?? [];
        foreach ($data['extra'] ?? [] as $key => $lines) {
            $more = array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $lines))));
            $inputs[$key] = [...array_values((array) ($inputs[$key] ?? [])), ...$more];
        }

        return app(LetterIssuer::class)->issue(
            $template,
            $matter,
            $recipients,
            $inputs,
            filled($data['letter_date'] ?? null) ? Carbon::parse($data['letter_date']) : now(),
            filled($data['letterhead_id'] ?? null) ? Letterhead::find($data['letterhead_id']) : null,
            auth()->id(),
        );
    }
}
