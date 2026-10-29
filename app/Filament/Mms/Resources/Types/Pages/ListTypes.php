<?php

namespace App\Filament\Mms\Resources\Types\Pages;

use App\Filament\Mms\Resources\Types\TypeResource;
use App\Models\MatterFieldDefinition;
use App\Services\MMS\MatterPhases;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

class ListTypes extends ListRecords
{
    protected static string $resource = TypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
            // Once: a dropdown custom field that held the phase (المرحلة) made its types' phases (MatterPhases).
            Action::make('phasesFromField')
                ->label(__('Phases from a custom field'))
                ->icon('heroicon-o-queue-list')
                ->color('gray')
                ->visible(fn (): bool => (auth()->user()?->can('Update:Type') ?? false)
                    && MatterFieldDefinition::query()->where('type', 'select_input')->whereHas('types')->exists())
                ->modalDescription(__('Its options become the phases of every type it is on, in the order and with the names below; each matter\'s value its phase; {{matter.custom.…}} of it in the folders and letters becomes {{matter.phase}}; and it is taken off those types. The values stay in the matters as they were.'))
                ->modalWidth('2xl')
                ->schema([
                    Select::make('field')
                        ->label(__('Custom field'))
                        ->options(fn (): array => MatterFieldDefinition::query()->where('type', 'select_input')->whereHas('types')->with('types:id,name')->orderBy('label')->get()
                            ->mapWithKeys(fn (MatterFieldDefinition $field): array => [$field->getKey() => $field->label.' — '.$field->types->pluck('name')->implode('، ')])->all())
                        ->required()
                        ->live()
                        ->afterStateUpdated(fn ($state, Set $set) => $set('phases', ($field = MatterFieldDefinition::find($state)) ? MatterPhases::optionsOf($field) : [])),
                    Repeater::make('phases')
                        ->label(__('Phases, in order'))
                        ->visible(fn (Get $get): bool => filled($get('field')))
                        ->schema([
                            Hidden::make('option'),
                            TextInput::make('phase')
                                ->label(fn (Get $get): string => __('Option ":option"', ['option' => (string) $get('option')]))
                                ->required()
                                ->maxLength(100),
                        ])
                        ->reorderable()
                        ->addable(false)
                        ->deletable(false),
                ])
                ->action(function (array $data): void {
                    $done = MatterPhases::fromField(MatterFieldDefinition::findOrFail($data['field']), array_values((array) ($data['phases'] ?? [])));

                    Notification::make()->success()
                        ->title(__('Phases set'))
                        ->body(__(':types types and :matters matters now have phases.', $done))
                        ->send();
                }),
        ];
    }
}
