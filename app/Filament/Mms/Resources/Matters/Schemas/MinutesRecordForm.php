<?php

namespace App\Filament\Mms\Resources\Matters\Schemas;

use App\Models\MatterMinutes;
use App\Models\Party;
use App\Services\MMS\Letters\LetterComposer;
use App\Services\MMS\Letters\MinutesService;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Wizard\Step;

/**
 * Recording a meeting (the Record the meeting page), in three steps:
 *
 *  1. attendees and opening — the meeting, who attended, the opening;
 *  2. questions and answers;
 *  3. other items and closing.
 *
 * The template's own items (deadlines …) each show in the step the
 * template puts them in ("Shows in step"); the third unless it says.
 */
class MinutesRecordForm
{
    /** A template item's step when the template doesn't say. */
    public const DEFAULT_STEP = 3;

    /**
     * The form's starting values.
     *
     * @return array<string, mixed>
     */
    public static function fill(MatterMinutes $minutes): array
    {
        return [
            'meeting_at' => $minutes->meeting_at?->format('Y-m-d H:i:s'),
            'meeting_link' => $minutes->meeting_link,
            'attendees' => $minutes->attendees ?: MinutesService::attendeeCandidates($minutes),
            'items' => $minutes->items ?? [],
            'inputs' => self::inputDefaults($minutes),
            'opening' => MinutesService::opening($minutes),
            'closing' => MinutesService::closing($minutes),
        ];
    }

    /**
     * @return list<Step>
     */
    public static function steps(MatterMinutes $minutes): array
    {
        return [
            Step::make(__('Attendees and opening'))
                ->icon('heroicon-o-user-group')
                ->schema([
                    Section::make()
                        ->columns(2)
                        ->schema([
                            DateTimePicker::make('meeting_at')->label(__('Meeting date and time'))->seconds(false)->required(),
                            TextInput::make('meeting_link')->label(__('Meeting link'))->url(),
                            Textarea::make('opening')
                                ->label(__('Opening paragraph'))
                                ->helperText(__('Where the template has {{minutes.opening}}. Placeholders and <<…>> parts work here.'))
                                ->rows(3)
                                ->visible(fn () => self::uses($minutes, 'minutes.opening'))
                                ->columnSpanFull(),
                        ]),
                    self::attendance($minutes),
                    ...self::templateFields($minutes, 1),
                ]),
            Step::make(__('Questions and answers'))
                ->icon('heroicon-o-chat-bubble-left-right')
                ->schema([
                    self::questions(),
                    ...self::templateFields($minutes, 2),
                ]),
            Step::make(__('Other items and closing'))
                ->icon('heroicon-o-document-check')
                ->schema([
                    ...self::templateFields($minutes, 3),
                    Section::make(__('Closing'))
                        ->visible(fn () => self::uses($minutes, 'minutes.closing'))
                        ->schema([
                            Textarea::make('closing')
                                ->label(__('Closing paragraph'))
                                ->helperText(__('Where the template has {{minutes.closing}}. Placeholders and <<…>> parts work here — e.g. << at {{minutes.end_time}}>>, filled with the time it is finalised.'))
                                ->rows(4),
                        ]),
                ]),
        ];
    }

    private static function attendance(MatterMinutes $minutes): Section
    {
        $arabic = (($minutes->template?->locale) ?: 'ar') !== 'en';

        return Section::make(__('Attendance'))
            ->description(__('Tick who attended. Pick an attendee from the parties and the main party they stand for; ID numbers, phones and emails typed here are kept on their party — and the phone and email on the main party too — for next time.'))
            ->schema([
                Repeater::make('attendees')
                    ->hiddenLabel()
                    ->columns(12)
                    ->addActionLabel(__('Add attendee'))
                    ->reorderable()
                    ->schema([
                        Toggle::make('present')->label(__('Attended'))->inline(false)->columnSpan(1),
                        Select::make('title')
                            ->label(__('Title'))
                            ->options(['السادة/' => 'السادة/', 'الأستاذ/' => 'الأستاذ/', 'الأستاذة/' => 'الأستاذة/', 'السيد/' => 'السيد/', 'السيدة/' => 'السيدة/', 'Messrs.' => 'Messrs.', 'Mr.' => 'Mr.', 'Ms.' => 'Ms.'])
                            ->placeholder('—')
                            ->columnSpan(2),
                        // Linked to a party in the system: their name, ID,
                        // latest phone and email filled in.
                        Select::make('party_id')
                            ->label(__('From the parties'))
                            ->placeholder(__('Not in the system'))
                            ->searchable()
                            ->getSearchResultsUsing(fn (string $search): array => Party::query()
                                ->where('name', 'like', '%'.$search.'%')
                                ->orderBy('name')
                                ->limit(20)
                                ->pluck('name', 'id')
                                ->all())
                            ->getOptionLabelUsing(fn ($value): ?string => Party::query()->whereKey($value)->value('name'))
                            ->live()
                            ->afterStateUpdated(function ($state, $old, Get $get, Set $set) use ($arabic): void {
                                // Only a party newly picked fills the line in — not one already
                                // on it (its name there), whose phone may have just been typed.
                                $party = filled($state) && (string) $state !== (string) $old ? Party::find($state) : null;
                                if (! $party || trim((string) $get('name')) === trim((string) $party->name)) {
                                    return;
                                }

                                $set('name', $party->name);
                                $set('title', $get('title') ?: (MinutesService::isCompany((string) $party->name)
                                    ? ($arabic ? 'السادة/' : 'Messrs.')
                                    : ($arabic ? 'الأستاذ/' : 'Mr.')));
                                $set('id_number', $party->extra['id_number'] ?? $get('id_number'));
                                $set('phone', $party->latestPhone() ?? $get('phone'));
                                $set('email', $party->latestEmail() ?? $get('email'));

                                // Picked as the very party they stood for: they stand for themselves.
                                if ((int) $get('represents') === (int) $party->getKey()) {
                                    $set('represents', null);
                                    $set('as', null);
                                }
                            })
                            ->columnSpan(4),
                        TextInput::make('name')->label(__('Name'))->required()->columnSpan(5),
                        // Whom they stand for, and how: the capacity follows
                        // ("محامٍ عن المدعي"), still editable.
                        Select::make('represents')
                            ->label(__('Represents'))
                            // Not themselves: one can't stand for oneself.
                            ->options(fn (Get $get): array => array_diff_key(MinutesService::mainParties($minutes), filled($get('party_id')) ? [(int) $get('party_id') => true] : []))
                            ->placeholder(__('Themselves'))
                            ->live()
                            ->afterStateUpdated(fn ($old, Get $get, Set $set) => self::followCapacity($minutes, $get, $set, $old, $get('as')))
                            ->columnSpan(4),
                        Select::make('as')
                            ->label(__('As'))
                            ->options(MinutesService::attendeeRoles())
                            ->placeholder('—')
                            ->live()
                            ->afterStateUpdated(fn ($old, Get $get, Set $set) => self::followCapacity($minutes, $get, $set, $get('represents'), $old))
                            ->columnSpan(2),
                        TextInput::make('capacity')->label(__('Capacity'))->columnSpan(3),
                        TextInput::make('id_number')->label(__('ID number'))->columnSpan(3),
                        TextInput::make('phone')->label(__('Phone'))->tel()->extraInputAttributes(['dir' => 'ltr'])->columnSpan(4),
                        TextInput::make('email')->label(__('Email'))->email()->extraInputAttributes(['dir' => 'ltr'])->columnSpan(5),
                    ]),
            ]);
    }

    private static function questions(): Section
    {
        return Section::make(__('Questions and answers'))
            ->schema([
                Repeater::make('items')
                    ->hiddenLabel()
                    ->addActionLabel(__('Add question or comment'))
                    ->reorderable()
                    ->columns(4)
                    ->schema([
                        Select::make('type')
                            ->label(__('Kind'))
                            ->options(['question' => __('Question'), 'comment' => __('Comment')])
                            ->default('question')
                            ->required()
                            ->live(),
                        Textarea::make('text')
                            ->label(fn (Get $get) => $get('type') === 'comment' ? __('Comment') : __('Question'))
                            ->rows(2)
                            ->required()
                            ->columnSpan(3),
                        Textarea::make('answer')
                            ->label(__('Answer'))
                            ->rows(4)
                            ->visible(fn (Get $get) => $get('type') !== 'comment')
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    /**
     * Whom an attendee stands for, or how, changed: their capacity follows
     * ("موظف عن المدعي") — unless it was written by hand (it is then neither
     * empty nor what the earlier choice made).
     */
    private static function followCapacity(MatterMinutes $minutes, Get $get, Set $set, mixed $oldRepresents, ?string $oldAs): void
    {
        $capacity = trim((string) $get('capacity'));
        $before = MinutesService::capacityFor($minutes, $oldRepresents, $oldAs);
        $now = MinutesService::capacityFor($minutes, $get('represents'), $get('as'));

        if ($now !== null && $now !== $capacity && ($capacity === '' || $capacity === $before)) {
            $set('capacity', $now);
        }
    }

    /**
     * Whether the minutes' wording has this placeholder.
     */
    public static function uses(MatterMinutes $minutes, string $key): bool
    {
        $body = LetterComposer::normalizeMergeTags((string) ($minutes->body ?: $minutes->template?->body));

        return (bool) preg_match('/\{\{\s*'.preg_quote($key, '/').'\s*\}\}/u', $body);
    }

    /**
     * The template's own items for one step, as the letters fill theirs.
     *
     * @return list<mixed>
     */
    private static function templateFields(MatterMinutes $minutes, int $step): array
    {
        $fields = collect($minutes->template?->inputs ?? [])
            ->filter(fn (array $input) => filled($input['key'] ?? null) && ($input['type'] ?? 'text') !== 'items')
            ->filter(fn (array $input) => self::stepOf($input) === $step)
            ->map(fn (array $input) => (match ($input['type'] ?? 'text') {
                'date' => DatePicker::make('inputs.'.$input['key']),
                'time' => TimePicker::make('inputs.'.$input['key'])->seconds(false),
                'textarea' => Textarea::make('inputs.'.$input['key'])->rows(3)->columnSpanFull(),
                'number' => TextInput::make('inputs.'.$input['key'])->numeric(),
                'url' => TextInput::make('inputs.'.$input['key'])->url()->columnSpanFull(),
                'select' => Select::make('inputs.'.$input['key'])->options(array_combine($input['options'] ?? [], $input['options'] ?? []) ?: []),
                'toggle' => Toggle::make('inputs.'.$input['key'])->inline(false),
                default => TextInput::make('inputs.'.$input['key']),
            })->label($input['label'] ?? $input['key'])->required(! empty($input['required']) && ($input['type'] ?? null) !== 'toggle'))
            ->values()
            ->all();

        return $fields === [] ? [] : [Section::make(__('Details'))->columns(2)->schema($fields)];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public static function stepOf(array $input): int
    {
        $step = (int) ($input['step'] ?? self::DEFAULT_STEP);

        return in_array($step, [1, 2, 3], true) ? $step : self::DEFAULT_STEP;
    }

    /**
     * Every template item with a value to start from (unset, a field the
     * browser fills isn't reliably sent back).
     *
     * @return array<string, mixed>
     */
    private static function inputDefaults(MatterMinutes $minutes): array
    {
        return collect($minutes->template?->inputs ?? [])
            ->filter(fn (array $input) => filled($input['key'] ?? null) && ($input['type'] ?? 'text') !== 'items')
            ->mapWithKeys(fn (array $input) => [$input['key'] => $minutes->inputs[$input['key']] ?? (($input['type'] ?? null) === 'toggle' ? false : null)])
            ->all();
    }
}
