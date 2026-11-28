<?php

namespace App\Filament\Mms\Resources\Parties\RelationManagers;

use App\Filament\Support\ContactFields;
use App\Models\Party;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * An external entity's contacts: the people and offices there (a court's secretaries, a bank's litigation officer),
 * each a party under it — external entities too, so a letter can be sent, or copied, to one of them
 * (Party::asLetterRecipient(), EmailSendFields::ccEntity()).
 */
class ContactsRelationManager extends RelationManager
{
    protected static string $relationship = 'contacts';

    public static function getModelLabel(): string
    {
        return __('Contact person');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Contacts');
    }

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Contacts');
    }

    /** Only for an external entity (not for one of its contacts). */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof Party
            && $ownerRecord->parent_id === null
            && in_array('external', (array) ($ownerRecord->role['role'] ?? []), true)
            && parent::canViewForRecord($ownerRecord, $pageClass);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('name')
                ->label(__('Name'))
                ->required()
                ->maxLength(255),
            TextInput::make('title')
                ->label(__('Position or department'))
                ->maxLength(255),
            ContactFields::phone(),
            ContactFields::email(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label(__('Name'))->searchable(),
                TextColumn::make('title')->label(__('Position or department'))->wrap()->searchable(),
                TextColumn::make('phone')->label(__('Phone'))->badge()->listWithLineBreaks()->limitList(5)->expandableLimitedList(),
                TextColumn::make('email')->label(__('Email'))->badge()->listWithLineBreaks()->limitList(5)->expandableLimitedList()->searchable(),
            ])
            ->headerActions([
                // An external entity too, where the entity is.
                CreateAction::make()
                    ->mutateDataUsing(fn (array $data): array => [
                        ...$data,
                        'role' => ['role' => ['external']],
                        'address' => $this->getOwnerRecord()->address,
                    ]),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
