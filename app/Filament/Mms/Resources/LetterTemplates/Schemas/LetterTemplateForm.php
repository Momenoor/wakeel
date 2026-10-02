<?php

namespace App\Filament\Mms\Resources\LetterTemplates\Schemas;

use App\Enums\LetterTemplateCategories;
use App\Filament\Support\LiveMergeTags;
use App\Filament\Support\RichEditorDirection;
use App\Models\Letterhead;
use App\Models\LetterItem;
use App\Services\MMS\Letters\Blocks\SavedSignatureBlock;
use App\Services\MMS\Letters\Blocks\SignatureBlock;
use App\Services\MMS\Letters\LetterComposer;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * A letter template: its letterhead, the form filled in when a letter is
 * issued from it (inputs), and its wording with {{placeholders}} — the
 * editor's "merge tags" menu lists every one, the template's own inputs
 * included.
 */
class LetterTemplateForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Template'))
                    ->columns(3)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('name')
                            ->label(__('Name'))
                            ->afterStateUpdated(fn ($state, Set $set) => $set('slug', Str::slug((string) $state) ?: Str::random(8)))
                            ->lazy()
                            ->required(),
                        TextInput::make('slug')
                            ->label(__('Code'))
                            ->unique(ignoreRecord: true)
                            ->required(),
                        Select::make('category')
                            ->label(__('Category'))
                            ->options(LetterTemplateCategories::class)
                            ->default(LetterTemplateCategories::LETTER)
                            ->live()
                            ->required(),
                        Select::make('locale')
                            ->label(__('Language'))
                            ->options(['ar' => __('Arabic'), 'en' => __('English')])
                            ->default('ar')
                            ->helperText(__('Arabic letters are laid out right to left.'))
                            ->required(),
                        Select::make('letterhead_id')
                            ->label(__('Letterhead'))
                            ->relationship('letterhead', 'name')
                            ->placeholder(__('The default letterhead'))
                            ->preload(),
                        Select::make('email_template_id')
                            ->label(__('Covering email'))
                            ->relationship('emailTemplate', 'name', fn ($query) => $query->where('is_active', true))
                            ->placeholder(__('The default covering email'))
                            ->helperText(__('Chosen when a letter from this template is sent by email; can be changed when sending.'))
                            ->preload(),
                        Toggle::make('is_active')
                            ->label(__('Active'))
                            ->default(true)
                            ->inline(false),
                        Select::make('types')
                            ->label(__('Matter types'))
                            ->relationship('types', 'name')
                            ->multiple()
                            ->preload()
                            ->searchable()
                            ->placeholder(__('All matter types'))
                            ->helperText(__('Issuing a letter on a matter offers only the templates for its type. Leave empty to offer this one for every type.'))
                            ->columnSpanFull(),
                        TextInput::make('subject')
                            ->label(__('Subject'))
                            ->required()
                            ->columnSpanFull()
                            ->helperText(__('Placeholders work here too, e.g. الدعوى رقم {{matter.number}} لسنة {{matter.year}}. Use {{subject}} in the letter to repeat it.')),
                    ]),

                Section::make(__('What to fill in when issuing'))
                    ->description(__('Each field appears in the form when a letter is issued from this template, and fills {{input.KEY}} in the letter.'))
                    ->columnSpanFull()
                    ->collapsible()
                    ->schema([
                        Repeater::make('inputs')
                            ->label(__('Fields'))->hiddenLabel()
                            ->addActionLabel(__('Add field'))
                            ->reorderable()
                            ->collapsible()
                            ->itemLabel(fn (array $state) => ($state['label'] ?? '').(filled($state['key'] ?? null) ? ' — {{input.'.$state['key'].'}}' : ''))
                            ->live(onBlur: true)
                            ->columns(4)
                            ->schema([
                                TextInput::make('label')
                                    ->label(__('Label'))
                                    ->required()
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(function ($state, Get $get, Set $set) {
                                        if (blank($get('key'))) {
                                            $set('key', Str::slug(Str::ascii((string) $state), '_') ?: 'field_'.Str::lower(Str::random(4)));
                                        }
                                    }),
                                TextInput::make('key')
                                    ->label(__('Key'))
                                    ->required()
                                    ->alphaDash()
                                    // The editor's placeholder menu follows it.
                                    ->live(onBlur: true)
                                    ->helperText(__('Used as {{input.KEY}}')),
                                Select::make('type')
                                    ->label(__('Type'))
                                    ->options([
                                        'text' => __('Text'),
                                        'textarea' => __('Long text'),
                                        'date' => __('Date'),
                                        'time' => __('Time'),
                                        'url' => __('Link'),
                                        'number' => __('Number'),
                                        'select' => __('Choice'),
                                        'toggle' => __('On / off switch'),
                                        'items' => __('Items from the library'),
                                    ])
                                    ->default('text')
                                    ->required()
                                    ->live(),
                                Toggle::make('required')
                                    ->label(__('Required'))
                                    ->inline(false),
                                TagsInput::make('options')
                                    ->label(__('Choices'))
                                    ->visible(fn (Get $get) => $get('type') === 'select')
                                    ->columnSpanFull(),
                                Select::make('group')
                                    ->label(__('Item group'))
                                    ->options(fn () => array_combine(LetterItem::groups(), LetterItem::groups()) ?: [])
                                    ->searchable()
                                    ->helperText(__('The library group to tick items from.'))
                                    ->visible(fn (Get $get) => $get('type') === 'items')
                                    ->columnSpan(2),
                                TextInput::make('heading')
                                    ->label(__('Heading above the list'))
                                    ->placeholder('من الطالبة (المدعي):')
                                    ->visible(fn (Get $get) => $get('type') === 'items')
                                    ->columnSpan(2),
                            ]),
                    ]),

                // Minutes: the wording their opening and closing start from,
                // completed while recording the meeting.
                Section::make(__('Opening and closing'))
                    ->description(__('Put {{minutes.opening}} and {{minutes.closing}} where they belong in the wording below; this is the text they start from when the meeting is recorded, where it can be changed. Placeholders and <<…>> parts work here — e.g. << at {{minutes.end_time}}>>, the time the minutes are finalised.'))
                    ->columnSpanFull()
                    ->visible(fn (Get $get) => self::isMinutes($get('category')))
                    ->schema([
                        Textarea::make('minutes_opening')
                            ->label(__('Opening paragraph'))
                            ->rows(3),
                        Textarea::make('minutes_closing')
                            ->label(__('Closing paragraph'))
                            ->rows(4),
                    ]),

                // Minutes: how {{minutes.attendees}} lays out who attended.
                Section::make(__('Attendees list'))
                    ->description(__('How {{minutes.attendees}} shows those who attended.'))
                    ->columnSpanFull()
                    ->columns(2)
                    ->visible(fn (Get $get) => self::isMinutes($get('category')))
                    ->schema([
                        Radio::make('minutes_attendees.layout')
                            ->label(__('Layout'))
                            ->options([
                                'grouped' => __('Grouped under each capacity'),
                                'list' => __('One numbered list'),
                                'table' => __('A table'),
                            ])
                            ->default('grouped')
                            ->live(),
                        Textarea::make('minutes_attendees.line')
                            ->label(__('Each attendee'))
                            ->placeholder(fn (Get $get) => LetterComposer::attendeeSettings([], $get('locale') !== 'en')['line'])
                            ->helperText(__('Leave empty for the standard line. Placeholders: :list. A part between << >> shows only when its placeholder is filled.', ['list' => '{{attendee.title}} {{attendee.name}} {{attendee.capacity}} {{attendee.id_number}} {{attendee.phone}} {{attendee.number}}']))
                            ->rows(2)
                            ->live(onBlur: true)
                            ->extraInputAttributes(['dir' => 'auto'])
                            ->visible(fn (Get $get) => ($get('minutes_attendees.layout') ?? 'grouped') !== 'table'),
                        TextInput::make('minutes_attendees.heading')
                            ->label(__('Capacity heading'))
                            ->placeholder('{{attendee.capacity}}:')
                            ->live(onBlur: true)
                            ->extraInputAttributes(['dir' => 'auto'])
                            ->visible(fn (Get $get) => ($get('minutes_attendees.layout') ?? 'grouped') === 'grouped'),
                        CheckboxList::make('minutes_attendees.columns')
                            ->label(__('Columns'))
                            ->options(fn (Get $get) => LetterComposer::attendeeColumns($get('locale') !== 'en'))
                            ->default(['number', 'name', 'capacity', 'id_number', 'signature'])
                            ->columns(3)
                            ->live()
                            ->visible(fn (Get $get) => $get('minutes_attendees.layout') === 'table'),
                        Placeholder::make('attendees_preview')
                            ->label(__('Preview'))
                            ->columnSpanFull()
                            ->content(fn (Get $get) => new HtmlString('<div dir="'.($get('locale') === 'en' ? 'ltr' : 'rtl').'" style="font-size: .9rem;">'
                                .LetterComposer::attendeesHtml(self::sampleAttendees($get('locale') !== 'en'), (array) ($get('minutes_attendees') ?? []), $get('locale') !== 'en')
                                .'</div>')),
                    ]),

                Section::make(__('Letter'))
                    ->columnSpanFull()
                    ->schema([
                        // The placeholder menu is redrawn as fields are added,
                        // renamed or removed above.
                        LiveMergeTags::wrap(
                            RichEditor::make('body')
                                ->label(__('Letter'))->hiddenLabel()
                                ->required()
                                ->toolbarButtons([
                                    ['bold', 'italic', 'underline', 'textColor', 'highlight'],
                                    ['h2', 'h3', 'bulletList', 'orderedList', 'horizontalRule', 'table'],
                                    ['alignStart', 'alignCenter', 'alignEnd', 'alignJustify'],
                                    ['mergeTags', 'customBlocks'],
                                    ['undo', 'redo'],
                                ])
                                ->mergeTags(fn (Get $get) => LetterComposer::catalog(null, $get('inputs') ?? []))
                                ->customBlocks([SavedSignatureBlock::class, SignatureBlock::class])
                                ->tap(RichEditorDirection::apply(...))
                                ->extraInputAttributes(fn (Get $get) => ['dir' => $get('locale') === 'en' ? 'ltr' : 'rtl', 'style' => 'min-height: 30rem;']),
                            fn (Get $get) => LetterComposer::catalog(null, $get('inputs') ?? []),
                        ),

                        TextEntry::make('placeholder_help')
                            ->hiddenLabel()
                            ->state(new HtmlString(e(__('Insert placeholders from the { } menu, or type them: {{recipients}} puts the addressee block (put it alone on its own line), {{input.KEY}} what was filled in, {{input.KEY.day}} a date\'s weekday, {{signature}} and {{stamp}} the letterhead\'s images. The blocks menu has a ready signature block: the expert\'s name with the signature and stamp, placed where you drop it. Write a part between << and >> (or [[ and ]]) to print it only when its placeholders are filled — e.g. <<The meeting is at {{meeting.time}}.>>')))),
                    ]),
            ]);
    }

    /**
     * Who attended, made up — for the attendees list's preview.
     *
     * @return list<array<string, mixed>>
     */
    private static function sampleAttendees(bool $arabic): array
    {
        return $arabic
            ? [
                ['present' => true, 'title' => 'السيد/', 'name' => 'أحمد علي', 'capacity' => 'المدعي', 'id_number' => '784-1990-1234567-1', 'phone' => '0501234567'],
                ['present' => true, 'title' => 'الأستاذ/', 'name' => 'محمد حسن', 'capacity' => 'وكيل المدعي', 'id_number' => '', 'phone' => '0559876543'],
                ['present' => true, 'title' => '', 'name' => 'شركة المثال', 'capacity' => 'المدعى عليها', 'id_number' => '', 'phone' => ''],
            ]
            : [
                ['present' => true, 'title' => 'Mr.', 'name' => 'Ahmed Ali', 'capacity' => 'Plaintiff', 'id_number' => '784-1990-1234567-1', 'phone' => '0501234567'],
                ['present' => true, 'title' => 'Mr.', 'name' => 'Mohamed Hassan', 'capacity' => 'Plaintiff\'s lawyer', 'id_number' => '', 'phone' => '0559876543'],
                ['present' => true, 'title' => '', 'name' => 'Example LLC', 'capacity' => 'Defendant', 'id_number' => '', 'phone' => ''],
            ];
    }

    private static function isMinutes(mixed $category): bool
    {
        return ($category instanceof LetterTemplateCategories ? $category->value : $category) === LetterTemplateCategories::MINUTES->value;
    }
}
