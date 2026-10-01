<?php

namespace App\Filament\Mms\Resources\EmailTemplates;

use App\Filament\Concerns\HasModuleGate;
use App\Filament\Mms\Resources\EmailTemplates\Pages\ManageEmailTemplates;
use App\Filament\Support\RichEditorDirection;
use App\Models\EmailTemplate;
use App\Services\MMS\Letters\LetterComposer;
use App\Services\MMS\Letters\LetterMailer;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ReplicateAction;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Covering emails for sending letters: subject and body with the letter's
 * placeholders, plus {{recipient.name}} when each recipient gets their own
 * email.
 */
class EmailTemplateResource extends Resource
{
    use HasModuleGate;

    protected static ?string $model = EmailTemplate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelope;

    protected static ?int $navigationSort = 5;

    public static function moduleGateKey(): string
    {
        return 'mms_communications';
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Communication');
    }

    public static function getModelLabel(): string
    {
        return __('Email template');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Email templates');
    }

    /**
     * @return array<string, string>
     */
    public static function placeholders(): array
    {
        return [
            ...LetterComposer::catalog(),
            'recipient.name' => __('Recipient name (separate emails)'),
            'recipient.role' => __('Recipient capacity (separate emails)'),
        ];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label(__('Name'))->required(),
            Select::make('locale')
                ->label(__('Language'))
                ->options(['ar' => __('Arabic'), 'en' => __('English')])
                ->default('ar')
                ->live()
                ->required(),
            TextInput::make('subject')
                ->label(__('Subject'))
                ->default('{{reference}} — {{subject}}')
                ->required()
                ->columnSpanFull(),
            RichEditor::make('body')
                ->label(__('Email'))
                ->default(fn () => LetterMailer::defaultCoverNote(true))
                ->required()
                ->toolbarButtons([
                    ['bold', 'italic', 'underline', 'link'],
                    ['bulletList', 'orderedList'],
                    ['alignStart', 'alignCenter', 'alignEnd'],
                    ['mergeTags'],
                    ['undo', 'redo'],
                ])
                ->mergeTags(fn () => static::placeholders())
                ->tap(RichEditorDirection::apply(...))
                ->extraInputAttributes(fn (Get $get) => ['dir' => $get('locale') === 'en' ? 'ltr' : 'rtl'])
                ->columnSpanFull(),
            Toggle::make('is_default')->label(__('Default')),
            Toggle::make('is_active')->label(__('Active'))->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label(__('Name'))->weight('bold')->searchable(),
                TextColumn::make('subject')->label(__('Subject'))->wrap(),
                IconColumn::make('is_default')->label(__('Default'))->boolean(),
                IconColumn::make('is_active')->label(__('Active'))->boolean(),
            ])
            ->recordActions([
                EditAction::make()->modalWidth('4xl'),
                ReplicateAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageEmailTemplates::route('/'),
        ];
    }
}
