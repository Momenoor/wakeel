<?php

namespace App\Filament\Mms\Resources\LeaveRequests\Schemas;

use App\Enums\LeaveType;
use App\Models\LeaveRequest;
use App\Models\Party;
use App\Services\MMS\LeaveBalanceService;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;

/**
 * What an employee fills in: who, when, and why.
 *
 * Deliberately NOT how the absence divides across leave types. An employee
 * asking for five days is making a request, not a determination — whether those
 * days come out of the annual balance, count as casual, or go unpaid is a
 * decision about entitlements the employee cannot see and should not be asked to
 * guess at. The approver makes that call, on the approval form.
 */
class LeaveRequestForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Leave Request'))
                    ->schema([
                        Select::make('party_id')
                            ->label(__('Employee'))
                            ->options(fn () => self::employeeOptions())
                            ->default(fn () => auth()->user()?->party?->id)
                            ->searchable()
                            ->required()
                            // Everyone but management files for themselves. The
                            // field is fixed rather than hidden so the employee
                            // can see whose leave they are booking; the page
                            // enforces it again on save, because a disabled
                            // field is a courtesy, not a barrier.
                            ->disabled(fn (): bool => ! self::managesOthers())
                            ->dehydrated()
                            ->live()
                            ->helperText(fn (): ?string => self::managesOthers()
                                ? __('You can file a request on behalf of any employee.')
                                : null),
                        DatePicker::make('start_date')
                            ->label(__('From'))
                            ->live(onBlur: true)
                            ->required(),
                        DatePicker::make('end_date')
                            ->label(__('To'))
                            ->live(onBlur: true)
                            ->required()
                            ->afterOrEqual('start_date'),
                        Select::make('requested_leave_type')
                            ->label(__('Requested Leave Type'))
                            ->options(LeaveType::class)
                            ->default(LeaveType::ANNUAL->value)
                            ->required()
                            ->live()
                            ->helperText(__('What you believe this absence should be. The approver still decides how it is actually split.')),
                        Text::make(fn (Get $get): ?string => self::balanceNote($get))
                            ->color('warning')
                            ->columnSpanFull(),
                        Textarea::make('comment')
                            ->label(__('Reason'))
                            ->rows(2)
                            ->columnSpanFull(),
                    ])->columns(3),
            ]);
    }

    /**
     * The annual balance, and how much of these dates it will not cover —
     * those days are unpaid.
     */
    private static function balanceNote(Get $get): ?string
    {
        $partyId = $get('party_id');

        if (blank($partyId)) {
            return null;
        }

        $balance = app(LeaveBalanceService::class)->balance((int) $partyId);
        $note = __('Annual leave balance: :days days.', ['days' => self::days($balance)]);

        $type = $get('requested_leave_type');
        $type = $type instanceof LeaveType ? $type : LeaveType::tryFrom((string) $type);

        if ($type !== LeaveType::ANNUAL || blank($get('start_date')) || blank($get('end_date'))) {
            return $note;
        }

        $requested = Carbon::parse($get('start_date'))->startOfDay()->diffInDays(Carbon::parse($get('end_date'))->startOfDay()) + 1;
        $unpaid = $requested - min($requested, max(0, floor($balance)));

        return $unpaid > 0
            ? $note.' '.__(':days of the :requested days requested will be unpaid.', ['days' => self::days($unpaid), 'requested' => self::days($requested)])
            : $note;
    }

    private static function days(float $days): string
    {
        return rtrim(rtrim(number_format($days, 1), '0'), '.');
    }

    /**
     * The employees this user may file for.
     *
     * @return array<int, string>
     */
    private static function employeeOptions(): array
    {
        if (self::managesOthers()) {
            return Party::withRole('employee')->orderBy('name')->pluck('name', 'id')->all();
        }

        $party = auth()->user()?->party;

        return $party === null ? [] : [$party->getKey() => $party->name];
    }

    private static function managesOthers(): bool
    {
        return auth()->user()?->can('manageOthers', LeaveRequest::class) ?? false;
    }
}
