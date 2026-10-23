<?php

namespace App\Filament\Mms\Resources\Parties\RelationManagers;

use App\Models\Court;
use App\Models\Party;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rules\Unique;

/**
 * A certified expert's registration numbers, one for each court they are registered with — a matter's letter
 * gives the one at its court ({{matter.experts.registration}}).
 */
class ExpertRegistrationsRelationManager extends RelationManager
{
    protected static string $relationship = 'expertRegistrations';

    public static function getModelLabel(): string
    {
        return __('Court registration');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Court registrations');
    }

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Court registrations');
    }

    /** Only for a certified expert. */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof Party && $ownerRecord->isCertifiedExpert() && parent::canViewForRecord($ownerRecord, $pageClass);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->columns(3)->components([
            Select::make('court_id')
                ->label(__('Court'))
                ->options(fn () => Court::query()->orderBy('name')->pluck('name', 'id'))
                ->searchable()
                ->required()
                // One number at each court.
                ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule) => $rule->where('party_id', $this->getOwnerRecord()->getKey())),
            TextInput::make('number')
                ->label(__('Registration number'))
                ->required()
                ->maxLength(100)
                ->extraInputAttributes(['dir' => 'ltr']),
            DatePicker::make('expires_on')
                ->label(__('Expiry date')),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('number')
            ->modifyQueryUsing(fn ($query) => $query->with('court:id,name'))
            ->columns([
                TextColumn::make('court.name')->label(__('Court')),
                TextColumn::make('number')->label(__('Registration number')),
                TextColumn::make('expires_on')->label(__('Expiry date'))->date()->placeholder('—')
                    ->color(fn ($state): ?string => $state && $state->isPast() ? 'danger' : null),
            ])
            // Changed by whoever may change the expert.
            ->headerActions([CreateAction::make()->authorize(fn (): bool => (bool) auth()->user()?->can('update', $this->getOwnerRecord()))])
            ->recordActions([
                EditAction::make()->authorize(fn (): bool => (bool) auth()->user()?->can('update', $this->getOwnerRecord())),
                DeleteAction::make()->authorize(fn (): bool => (bool) auth()->user()?->can('update', $this->getOwnerRecord())),
            ]);
    }
}
