<?php

namespace App\Filament\Mms\Resources\Courts\RelationManagers;

use App\Filament\Mms\Resources\Matters\MatterResource;
use App\Filament\Mms\Resources\Matters\Tables\MattersTable;
use Filament\Actions\ViewAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class MattersRelationManager extends RelationManager
{
    protected static string $relationship = 'matters';

    public static function getModelLabel(): string
    {
        return __('Matter');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Matters');
    }

    protected static ?string $relatedResource = MatterResource::class;

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Matters');
    }

    public function table(Table $table): Table
    {
        return MattersTable::configure($table)
            ->headerActions([])
            ->actions([
                ViewAction::make(),
            ])
            ->bulkActions([]);
    }
}
