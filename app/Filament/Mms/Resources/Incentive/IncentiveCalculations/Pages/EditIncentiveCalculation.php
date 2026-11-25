<?php

namespace App\Filament\Mms\Resources\Incentive\IncentiveCalculations\Pages;

use App\Filament\Mms\Resources\Incentive\IncentiveCalculations\IncentiveCalculationResource;
use App\Models\IncentiveCalculation;
use App\Services\MMS\IncentiveReversals;
use App\Services\MMS\MarketingCommissions;
use App\Support\ActivityLog;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\DB;

class EditIncentiveCalculation extends EditRecord
{
    protected static string $resource = IncentiveCalculationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }

    /**
     * Adjustments from previous periods, or marketing commissions, switched on or off on a draft: settled in it now,
     * or given back to wait for an upcoming calculation — its other lines are not touched (they do not depend on them).
     */
    protected function afterSave(): void
    {
        /** @var IncentiveCalculation $record */
        $record = $this->record;

        if ($record->isDraft() && $record->wasChanged('apply_previous_adjustments')) {
            ActivityLog::quietly(fn () => DB::transaction(fn () => app(IncentiveReversals::class)->apply($record)));
        }

        if ($record->isDraft() && $record->wasChanged('apply_marketing_commissions')) {
            DB::transaction(fn () => app(MarketingCommissions::class)->apply($record));
        }
    }
}
