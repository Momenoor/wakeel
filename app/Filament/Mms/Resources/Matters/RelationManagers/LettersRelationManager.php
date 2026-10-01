<?php

namespace App\Filament\Mms\Resources\Matters\RelationManagers;

use App\Filament\Concerns\HasRelationManagerPermission;
use App\Models\CalendarEvent;
use App\Models\EmailTemplate;
use App\Models\Letterhead;
use App\Models\LetterItem;
use App\Models\LetterTemplate;
use App\Models\Matter;
use App\Models\MatterLetter;
use App\Services\MMS\Letters\LetterComposer;
use App\Services\MMS\Letters\LetterIssuer;
use App\Services\MMS\Letters\LetterMailer;
use App\Services\MMS\SenderMailer;
use App\Support\ScreenPermissions;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

/**
 * A matter's letters, and issuing a new one: pick a template, tick who it
 * goes to, fill in the template's fields (items ticked from the library),
 * and it's numbered JPA/{year}/{number}/{n} and ready as PDF or Word.
 */
class LettersRelationManager extends RelationManager
{
    use HasRelationManagerPermission;

    public static function viewPermission(): string
    {
        return ScreenPermissions::MATTER_LETTERS;
    }

    protected static string $relationship = 'letters';

    public static function getModelLabel(): string
    {
        return __('Letter');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Letters');
    }

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
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->description(fn (MatterLetter $record) => $record->sent_at?->format('d/m/Y H:i')),
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
                $this->emailAction(),
                ActionGroup::make([
                    $this->editAction(),
                    DeleteAction::make(),
                ]),
            ]);
    }

    /**
     * Email an issued letter: as an attachment under a covering email, or
     * as the email body; to everyone at once or separately.
     */
    private function emailAction(): Action
    {
        return Action::make('email')
            ->label(__('Send by email'))
            ->icon('heroicon-o-paper-airplane')
            ->color('success')
            ->modalWidth('3xl')
            ->modalSubmitActionLabel(__('Send'))
            ->fillForm(fn (MatterLetter $record) => [
                // The mailbox this matter's letters last went from.
                'sender' => MatterLetter::query()->where('matter_id', $record->matter_id)->whereNotNull('sender_key')->latest('sent_at')->value('sender_key')
                    ?? array_key_first(SenderMailer::options()),
                'mode' => LetterMailer::ATTACHMENT,
                'email_template_id' => EmailTemplate::default()?->getKey(),
                'formats' => ['pdf'],
                'recipients' => $record->recipients->pluck('id')->all(),
                'separate' => false,
            ])
            ->schema(fn (MatterLetter $record) => [
                Select::make('sender')
                    ->label(__('Send from'))
                    ->options(SenderMailer::options())
                    ->required(),
                Radio::make('mode')
                    ->label(__('Send the letter'))
                    ->options([
                        LetterMailer::ATTACHMENT => __('As an attachment, with a covering email'),
                        LetterMailer::BODY => __('As the email itself'),
                    ])
                    ->required()
                    ->live(),
                Select::make('email_template_id')
                    ->label(__('Covering email'))
                    ->options(fn () => EmailTemplate::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                    ->placeholder(__('A short standard note'))
                    ->visible(fn (Get $get) => $get('mode') === LetterMailer::ATTACHMENT),
                CheckboxList::make('formats')
                    ->label(__('Attach as'))
                    ->options(['pdf' => 'PDF', 'docx' => 'Word'])
                    ->required(fn (Get $get) => $get('mode') === LetterMailer::ATTACHMENT)
                    ->visible(fn (Get $get) => $get('mode') === LetterMailer::ATTACHMENT)
                    ->columns(2),
                CheckboxList::make('recipients')
                    ->label(__('To'))
                    ->options($record->recipients->mapWithKeys(fn ($r) => [$r->id => trim($r->name.($r->role ? ' ('.$r->role.')' : ''))]))
                    ->descriptions($record->recipients->mapWithKeys(fn ($r) => [$r->id => implode(' · ', $r->emails ?: array_filter([$r->email])) ?: __('No email')]))
                    ->bulkToggleable(),
                TagsInput::make('cc')
                    ->label(__('CC'))
                    ->placeholder('name@example.com')
                    ->nestedRecursiveRules(['email']),
                Toggle::make('separate')
                    ->label(__('A separate email to each recipient'))
                    ->helperText(__('Each then sees only their own address, and {{recipient.name}} greets them by name.')),
            ])
            ->action(function (MatterLetter $record, array $data) {
                $result = app(LetterMailer::class)->send(
                    $record,
                    $data['sender'],
                    $data['mode'],
                    filled($data['email_template_id'] ?? null) ? EmailTemplate::find($data['email_template_id']) : null,
                    $data['formats'] ?? [],
                    array_map('intval', $data['recipients'] ?? []),
                    array_values($data['cc'] ?? []),
                    (bool) ($data['separate'] ?? false),
                );

                $notification = Notification::make()
                    ->title(__('Sent: :sent, failed: :failed, without email: :skipped', [
                        'sent' => $result['sent'],
                        'failed' => $result['failed'],
                        'skipped' => $result['skipped'],
                    ]))
                    ->body($result['errors'] ? implode("\n", array_unique($result['errors'])) : null);

                match (true) {
                    $result['failed'] > 0 && $result['sent'] === 0 => $notification->danger(),
                    $result['failed'] > 0 || $result['skipped'] > 0 => $notification->warning(),
                    default => $notification->success(),
                };

                $notification->send();
            });
    }

    /**
     * "لعناية السيد/ … المحترم" under the addressees, when filled in.
     */
    private static function attentionField(): TextInput
    {
        return TextInput::make('attention')
            ->label(__('For the attention of'))
            ->placeholder(__('Name'))
            ->maxLength(255)
            ->helperText(__('Printed under the addressees as "لعناية السيد/ … المحترم". Leave empty for none.'));
    }

    /**
     * Change an issued letter: its date, letterhead, attention line and
     * recipients. Its reference and wording stay as issued.
     */
    private function editAction(): Action
    {
        return Action::make('editLetter')
            ->label(__('Edit'))
            ->icon('heroicon-o-pencil')
            ->modalHeading(fn (MatterLetter $record) => __('Edit letter :reference', ['reference' => $record->reference]))
            ->modalWidth('4xl')
            ->fillForm(fn (MatterLetter $record): array => [
                'letter_date' => $record->letter_date?->toDateString(),
                'letterhead_id' => $record->letterhead_id,
                'attention' => $record->attention,
                'recipients' => $record->recipients->map(fn ($recipient) => [
                    'name' => $recipient->name,
                    'role' => $recipient->role,
                    'emails' => $recipient->emails ?: array_values(array_filter([$recipient->email])),
                    'party_id' => $recipient->recipient_id,
                ])->all(),
            ])
            ->schema([
                Section::make()
                    ->columns(2)
                    ->schema([
                        DatePicker::make('letter_date')->label(__('Letter date'))->required(),
                        Select::make('letterhead_id')
                            ->label(__('Letterhead'))
                            ->options(fn () => Letterhead::query()->orderBy('name')->pluck('name', 'id'))
                            ->placeholder(__('The template\'s letterhead')),
                    ]),
                Section::make(__('Recipients'))
                    ->schema([
                        Repeater::make('recipients')
                            ->hiddenLabel()
                            ->addActionLabel(__('Add recipient'))
                            ->minItems(1)
                            ->columns(3)
                            ->schema([
                                TextInput::make('name')->label(__('Name'))->required(),
                                TextInput::make('role')->label(__('Capacity')),
                                TagsInput::make('emails')->label(__('Emails'))->nestedRecursiveRules(['email']),
                                Hidden::make('party_id'),
                            ]),
                        self::attentionField(),
                    ]),
            ])
            ->action(function (MatterLetter $record, array $data): void {
                app(LetterIssuer::class)->revise(
                    $record,
                    array_values(array_map(fn (array $r): array => [
                        'name' => (string) $r['name'],
                        'role' => $r['role'] ?? null,
                        'emails' => array_values($r['emails'] ?? []),
                        'party_id' => filled($r['party_id'] ?? null) ? (int) $r['party_id'] : null,
                    ], $data['recipients'] ?? [])),
                    Carbon::parse($data['letter_date']),
                    filled($data['letterhead_id'] ?? null) ? Letterhead::find($data['letterhead_id']) : null,
                    $data['attention'] ?? null,
                );

                Notification::make()->success()->title(__('Letter :reference updated', ['reference' => $record->reference]))->send();
            });
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
                $letters = $this->issue($this->getOwnerRecord(), $data);

                if (count($letters) > 1) {
                    Notification::make()
                        ->success()
                        ->title(__(':count letters issued, one for each recipient', ['count' => count($letters)]))
                        ->body(new HtmlString(collect($letters)->map(fn (MatterLetter $letter) => e($letter->reference.' — '.$letter->recipients->pluck('name')->implode(', ')))->implode('<br>')))
                        ->send();

                    return;
                }

                $letter = $letters[0];

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
                        // Only the templates for this matter's type (and those for every type).
                        ->options(fn () => LetterTemplate::query()->forMatterType($matter->type_id)->orderBy('name')->pluck('name', 'id'))
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
                        ->live()
                        ->columns(2),
                    Repeater::make('extra_recipients')
                        ->label(__('Other recipients'))
                        ->addActionLabel(__('Add recipient'))
                        ->defaultItems(0)
                        ->live()
                        ->columns(3)
                        ->schema([
                            TextInput::make('name')->label(__('Name'))->required(),
                            TextInput::make('role')->label(__('Capacity')),
                            TagsInput::make('emails')->label(__('Emails'))->nestedRecursiveRules(['email']),
                        ]),
                    // Several recipients: one letter to all of them, or each
                    // their own letter (own reference, only them on it).
                    Toggle::make('separately')
                        ->label(__('Issue a separate letter to each recipient'))
                        ->helperText(__('Off: one letter addressed to all of them. On: each recipient gets their own letter, with its own reference.'))
                        ->default(false)
                        ->visible(fn (Get $get): bool => count($get('recipients') ?? []) + count($get('extra_recipients') ?? []) > 1),
                    self::attentionField(),
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
     * The letter — or, issued separately, one letter per recipient.
     *
     * @param  array<string, mixed>  $data
     * @return list<MatterLetter>
     */
    public function issue(Matter $matter, array $data): array
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

        $date = filled($data['letter_date'] ?? null) ? Carbon::parse($data['letter_date']) : now();
        $letterhead = filled($data['letterhead_id'] ?? null) ? Letterhead::find($data['letterhead_id']) : null;
        $issuer = app(LetterIssuer::class);

        // Separately: the same letter for each recipient alone.
        $groups = ! empty($data['separately']) && count($recipients) > 1
            ? array_map(fn (array $recipient): array => [$recipient], $recipients)
            : [$recipients];

        return array_map(
            fn (array $group): MatterLetter => $issuer->issue($template, $matter, $group, $inputs, $date, $letterhead, auth()->id(), $data['attention'] ?? null),
            $groups,
        );
    }
}
