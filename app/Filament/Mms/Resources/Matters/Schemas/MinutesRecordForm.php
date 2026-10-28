<?php

namespace App\Filament\Mms\Resources\Matters\Schemas;

use App\Filament\Support\TeamsAttendanceSection;
use App\Models\MatterMinutes;
use App\Models\Party;
use App\Services\MMS\Letters\LetterComposer;
use App\Services\MMS\Letters\MinutesService;
use App\Services\MMS\TeamsAttendance;
use Closure;
use Filament\Actions\Action;
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
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

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
            // Not yet recorded: as the previous meeting, else the parties.
            'attendees' => $minutes->attendees ?: MinutesService::startingAttendees($minutes),
            // Shown in the live view unless switched off (older items have no switch).
            'items' => array_map(fn ($item) => is_array($item) ? [...$item, 'live' => $item['live'] ?? true] : $item, (array) ($minutes->items ?? [])),
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
                    self::teamsAttendance($minutes),
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
                        // A line added at the meeting: someone there.
                        Toggle::make('present')->label(__('Attended'))->default(true)->inline(false)->columnSpan(1),
                        Select::make('title')
                            ->label(__('Title'))
                            ->options(['السادة/' => 'السادة/', 'الأستاذ/' => 'الأستاذ/', 'الأستاذة/' => 'الأستاذة/', 'السيد/' => 'السيد/', 'السيدة/' => 'السيدة/', 'Messrs.' => 'Messrs.', 'Mr.' => 'Mr.', 'Ms.' => 'Ms.'])
                            ->placeholder('—')
                            ->columnSpan(2),
                        // Linked to a party in the system: their name, ID,
                        // latest phone and email filled in.
                        // A line of one of the matter's parties: its parties to pick
                        // from. A line added: every party, of any role — found by
                        // name, phone, email or role.
                        Select::make('party_id')
                            ->label(__('From the parties'))
                            ->placeholder(__('Not in the system'))
                            ->searchable()
                            ->options(fn (Get $get): array => self::partyOptions(self::isMatterLine($minutes, $get('party_id'))
                                ? Party::query()->whereIn('id', self::matterPartyIds($minutes))
                                : Party::query()))
                            ->getSearchResultsUsing(fn (string $search, Get $get): array => self::partyOptions(self::isMatterLine($minutes, $get('party_id'))
                                ? self::searchParties($search)->whereIn('id', self::matterPartyIds($minutes))
                                : self::searchParties($search)))
                            ->getOptionLabelUsing(fn ($value, Get $get): ?string => self::partyLabels((array) ($get('../../attendees') ?? []))[(int) $value]
                                ?? (($party = Party::with('parent')->find($value)) ? self::partyLabel($party) : null))
                            ->live()
                            ->afterStateUpdated(function ($state, $old, Get $get, Set $set) use ($arabic, $minutes): void {
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

                                // One who belongs to a party of the matter stands for it.
                                if (blank($get('represents')) && filled($party->parent_id)
                                    && array_key_exists((int) $party->parent_id, MinutesService::mainParties($minutes))) {
                                    $as = $get('as');
                                    $set('represents', (int) $party->parent_id);
                                    $set('as', $as ?: 'agent');
                                    self::followCapacity($minutes, $get, $set, null, $as);
                                }
                            })
                            ->columnSpan(4),
                        TextInput::make('name')->label(__('Name'))->required()->columnSpan(5),
                        // Whom they stand for, and how: the capacity follows
                        // ("وكيلاً عن (السادة/ … - المدعي)"), still editable.
                        Select::make('represents')
                            ->label(__('Represents'))
                            // Not themselves: one can't stand for oneself.
                            ->options(fn (Get $get): array => array_diff_key(MinutesService::mainParties($minutes), filled($get('party_id')) ? [(int) $get('party_id') => true] : []))
                            ->placeholder(__('Themselves'))
                            ->live()
                            ->afterStateUpdated(function ($old, Get $get, Set $set) use ($minutes): void {
                                $as = $get('as');
                                // Standing for someone: their agent, unless said otherwise.
                                if (filled($get('represents')) && blank($as)) {
                                    $set('as', 'agent');
                                }

                                self::followCapacity($minutes, $get, $set, $old, $as);
                            })
                            ->columnSpan(4),
                        Select::make('as')
                            ->label(__('As'))
                            ->options(fn (Get $get): array => MinutesService::attendeeRoles($get('as')))
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

    /**
     * Who joined the meeting's Teams call, and when each joined and left (TeamsAttendance) — read on its own once
     * the meeting is over, or now; those found ticked as attended, those on no line to add.
     */
    private static function teamsAttendance(MatterMinutes $minutes): Section
    {
        return TeamsAttendanceSection::make(
            $minutes,
            afterRead: function (Get $get, Set $set, array $people): string {
                $set('attendees', TeamsAttendance::markPresent((array) $get('attendees'), $people));

                return __('Those on the list are ticked as attended.');
            },
            actions: fn (Closure $people): array => [
                Action::make('addTeamsAttendees')
                    ->label(__('Add those not on the list'))
                    ->icon(Heroicon::UserPlus)
                    ->link()
                    ->visible(fn (Get $get): bool => TeamsAttendance::unlisted((array) $get('attendees'), $people()) !== [])
                    ->action(function (Get $get, Set $set) use ($people): void {
                        $lines = (array) $get('attendees');

                        foreach (TeamsAttendance::unlisted($lines, $people()) as $person) {
                            $lines[(string) Str::uuid()] = ['present' => true, 'name' => $person['name'], 'email' => $person['email'], 'party_id' => $person['party_id'] ?? null];
                        }

                        $set('attendees', $lines);
                    }),
            ],
        );
    }

    /**
     * The matter's parties, representatives and experts — not those added
     * at its meetings.
     *
     * @return list<int>
     */
    private static function matterPartyIds(MatterMinutes $minutes): array
    {
        // Once a request: asked of each line.
        return once(fn (): array => $minutes->matter?->matterParties()
            ->where(fn ($q) => $q->whereNull('role')->orWhere('role', '!=', 'attendee'))
            ->pluck('party_id')->map(fn ($id) => (int) $id)->unique()->values()->all() ?? []);
    }

    /** A line of one of the matter's parties (else one added at the meeting). */
    private static function isMatterLine(MatterMinutes $minutes, mixed $partyId): bool
    {
        return filled($partyId) && in_array((int) $partyId, self::matterPartyIds($minutes), true);
    }

    /**
     * Parties by name, legal name, phone or email — or holding a role (or an
     * expert's type) of that name.
     *
     * @return Builder<Party>
     */
    public static function searchParties(string $search): Builder
    {
        $search = trim($search);
        $like = '%'.$search.'%';
        $matches = fn (array $labels): array => array_keys(array_filter($labels, fn (string $label): bool => $search !== '' && mb_stripos($label, $search) !== false));

        return Party::query()->where(function (Builder $q) use ($like, $matches): void {
            $q->where('name', 'like', $like)
                ->orWhere('legal_name', 'like', $like)
                ->orWhere('phone', 'like', $like)
                ->orWhere('email', 'like', $like);

            foreach ($matches(Party::roleOptions()) as $role) {
                $q->orWhere(fn (Builder $r) => $r->withRole($role));
            }

            foreach ($matches(Party::expertTypeOptions()) as $type) {
                $q->orWhere(fn (Builder $r) => $r->withRole('expert', $type));
            }
        });
    }

    /**
     * @param  Builder<Party>  $query
     * @return array<int, string>
     */
    private static function partyOptions(Builder $query): array
    {
        return $query->with('parent')->orderBy('name')->limit(50)->get()
            ->mapWithKeys(fn (Party $party): array => [$party->getKey() => self::partyLabel($party)])
            ->all();
    }

    /**
     * Every attendee line's party, labelled — read at once, not one line
     * at a time.
     *
     * @param  array<array-key, mixed>  $attendees
     * @return array<int, string>
     */
    private static function partyLabels(array $attendees): array
    {
        $ids = collect($attendees)->pluck('party_id')->filter()->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();

        return once(fn (): array => Party::with('parent')->whereKey($ids)->get()
            ->mapWithKeys(fn (Party $party): array => [$party->getKey() => self::partyLabel($party)])
            ->all());
    }

    /** "سالم الموظف — موظف (شركة المهاد)". */
    public static function partyLabel(Party $party): string
    {
        $role = $party->roleLabel();

        return trim($party->name
            .($role !== '' ? ' — '.$role : '')
            .($party->parent ? ' ('.$party->parent->name.')' : ''));
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
                        Toggle::make('live')
                            ->label(__('Show in the live view'))
                            ->helperText(__('Off: the attendees watching do not see it; the minutes still have it.'))
                            ->default(true)
                            ->columnSpanFull(),
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
     * ("مفوضاً عن (…)") — unless it was written by hand (it is then neither
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
