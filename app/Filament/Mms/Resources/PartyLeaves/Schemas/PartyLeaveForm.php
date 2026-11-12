<?php

namespace App\Filament\Mms\Resources\PartyLeaves\Schemas;

use App\Enums\LeaveType;
use App\Models\Party;
use App\Models\PartyLeave;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class PartyLeaveForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components(self::getFormSchema());
    }

    /** What the chosen type does to the annual balance for these dates, said before the vacation is saved. */
    public static function balanceEffect(mixed $type, mixed $start, mixed $end): string
    {
        if (! LeaveType::fromBalance($type)) {
            return __('This type does not take days off the annual leave balance.');
        }

        $days = PartyLeave::daysBetween($start, $end);

        return $days > 0
            ? __('Takes :days days off the annual leave balance.', ['days' => rtrim(rtrim(number_format($days, 1), '0'), '.')])
            : __('Takes its days off the annual leave balance.');
    }

    public static function getFormSchema(): array
    {
        return [
            Section::make(__('Leave Details'))
                ->description(__('Days inside this range are excluded from completion-day and monthly-quota calculations for this person.'))
                ->schema([
                    // Per employee — the same person record an assistant's
                    // incentive is calculated on, so a vacation entered here
                    // counts there too. A vacation already recorded for
                    // someone not marked as an employee still shows who it is
                    // for when edited.
                    Select::make('party_id')
                        ->label(__('Employee'))
                        ->options(fn (?PartyLeave $record): array => Party::withRole('employee')
                            ->when($record?->party_id, fn ($query, $id) => $query->orWhere('id', $id))
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all())
                        ->searchable()
                        ->required(),
                    DatePicker::make('start_date')
                        ->label(__('Start Date'))
                        ->live()
                        ->required(),
                    DatePicker::make('end_date')
                        ->label(__('End Date'))
                        ->live()
                        ->required()
                        ->afterOrEqual('start_date'),
                    // The type decides the pay (an unpaid one withholds the days' pay) and whether the days come
                    // off the annual balance (annual leave always; the others as Settings → Payroll says): the
                    // balance entry is written with the vacation (PartyLeave, LeaveBalanceService::syncManualLeave()).
                    Select::make('leave_type')
                        ->label(__('Leave Type'))
                        ->options(LeaveType::class)
                        ->live()
                        ->required()
                        ->helperText(fn (Get $get): string => self::balanceEffect($get('leave_type'), $get('start_date'), $get('end_date')))
                        ->columnSpanFull(),
                    Textarea::make('reason')
                        ->label(__('Reason'))
                        ->rows(2)
                        ->columnSpanFull(),
                ])->columns(3),
        ];
    }
}
