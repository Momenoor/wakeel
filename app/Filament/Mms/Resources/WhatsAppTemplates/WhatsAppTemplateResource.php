<?php

namespace App\Filament\Mms\Resources\WhatsAppTemplates;

use App\Filament\Concerns\HasModuleGate;
use App\Filament\Mms\Clusters\Templates;
use App\Filament\Mms\Resources\WhatsAppTemplates\Pages\ManageWhatsAppTemplates;
use App\Models\WhatsAppTemplate;
use App\Services\MMS\Letters\LetterComposer;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ReplicateAction;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

/**
 * WhatsApp message templates: each one created and approved first in Meta's
 * WhatsApp Manager, then set up here under the same name and language,
 * with what fills each of its parameters.
 */
class WhatsAppTemplateResource extends Resource
{
    use HasModuleGate;

    protected static ?string $model = WhatsAppTemplate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static ?int $navigationSort = 7;

    protected static ?string $cluster = Templates::class;

    public static function moduleGateKey(): string
    {
        return 'mms_communications';
    }

    public static function getModelLabel(): string
    {
        return __('WhatsApp template');
    }

    public static function getPluralModelLabel(): string
    {
        return __('WhatsApp templates');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('name')->label(__('Name'))->required(),
            Select::make('purpose')
                ->label(__('Used for'))
                ->options(WhatsAppTemplate::purposes())
                ->default(WhatsAppTemplate::MINUTES_SIGNATURE)
                ->required(),
            TextInput::make('meta_name')
                ->label(__('Template name in Meta'))
                ->helperText(__('Exactly as in WhatsApp Manager, e.g. minutes_for_signature.'))
                ->regex('/^[a-z0-9_]+$/')
                ->required(),
            TextInput::make('language')
                ->label(__('Language code'))
                ->helperText(__('As approved in Meta, e.g. ar, ar_AE or en_US.'))
                ->default('ar')
                ->required(),
            Radio::make('header')
                ->label(__('Header'))
                ->options(['document' => __('Document (the minutes PDF)'), 'none' => __('None')])
                ->default('document')
                ->inline()
                ->required()
                ->columnSpanFull(),
            Textarea::make('body')
                ->label(__('Message text'))
                ->helperText(__('The text as approved in Meta, its parameters written {{name}} — shown in the preview when sending. Meta sends its own approved copy.'))
                ->rows(7)
                ->required()
                ->extraInputAttributes(['dir' => 'auto'])
                ->columnSpanFull(),
            Repeater::make('parameters')
                ->label(__('Parameters'))
                ->helperText(new HtmlString(e(__('Each parameter in the text, and what fills it — fixed text, or placeholders such as :examples.', ['examples' => '{{recipient.name}}, {{minutes.number}}, {{matter.reference}}, {{meeting.date}}']))))
                ->schema([
                    TextInput::make('name')->label(__('Parameter'))->regex('/^[a-z0-9_]+$/')->required(),
                    TextInput::make('value')->label(__('Filled with'))->required()->datalist(array_map(fn (string $key) => '{{'.$key.'}}', ['recipient.name', 'recipient.salutation', 'recipient.title', 'recipient.suffix', ...array_keys(LetterComposer::catalog())])),
                ])
                ->columns(2)
                ->addActionLabel(__('Add parameter'))
                ->columnSpanFull(),
            Textarea::make('acknowledgement')
                ->label(__('Reply when the signed copy arrives'))
                ->helperText(__('Sent once, when the first signed copy comes back. Leave empty to send nothing.').' '.__('Placeholders work here: :examples', ['examples' => '{{recipient.name}}, {{minutes.number}}, {{matter.reference}}, {{company.phone}}, {{company.whatsapp}}, {{company.email}}']))
                ->rows(3)
                ->extraInputAttributes(['dir' => 'auto'])
                ->columnSpanFull(),
            // A text instead of the file: how to send it, and how to reach us.
            Textarea::make('text_reply')
                ->label(__('Reply when a text arrives instead of the signed copy'))
                ->helperText(__('Sent once for each minutes, to an attendee who writes back without the file. Leave empty to send nothing.').' '.__('Placeholders work here: :examples', ['examples' => '{{recipient.name}}, {{minutes.number}}, {{matter.reference}}, {{company.phone}}, {{company.whatsapp}}, {{company.email}}']))
                ->rows(5)
                ->extraInputAttributes(['dir' => 'auto'])
                ->columnSpanFull(),
            Toggle::make('is_default')->label(__('Default')),
            Toggle::make('is_active')->label(__('Active'))->default(true),
            Section::make(__('Setting it up in Meta'))
                ->collapsed()
                ->columnSpanFull()
                ->schema([
                    TextEntry::make('meta_help')
                        ->hiddenLabel()
                        ->state(new HtmlString(nl2br(e(__('In WhatsApp Manager → Message templates → Create template: category Utility, the name and language above, header "Document", and the message text with the parameters by name (no parameter at the very start or end). Once Meta approves it, it can be sent.'))))),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label(__('Name'))->weight('bold')->searchable(),
                TextColumn::make('meta_name')->label(__('Template name in Meta'))->fontFamily('mono'),
                TextColumn::make('purpose')->label(__('Used for'))->formatStateUsing(fn (string $state) => WhatsAppTemplate::purposes()[$state] ?? $state),
                TextColumn::make('language')->label(__('Language code')),
                IconColumn::make('is_default')->label(__('Default'))->boolean(),
                IconColumn::make('is_active')->label(__('Active'))->boolean(),
            ])
            ->recordActions([
                EditAction::make()->modalWidth('6xl'),
                ReplicateAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageWhatsAppTemplates::route('/'),
        ];
    }
}
