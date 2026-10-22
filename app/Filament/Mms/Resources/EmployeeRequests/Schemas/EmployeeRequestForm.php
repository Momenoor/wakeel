<?php

namespace App\Filament\Mms\Resources\EmployeeRequests\Schemas;

use App\Enums\EmployeeRequestType;
use App\Filament\Mms\Resources\EmployeeLoans\Schemas\EmployeeLoanForm;
use App\Filament\Mms\Resources\LeaveRequests\LeaveRequestResource;
use App\Models\EmployeeRequest;
use App\Models\LetterTemplate;
use App\Models\Party;
use App\Services\MMS\EmployeeRequestService;
use App\Services\MMS\FlightTicketService;
use App\Support\Currency;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * An employee's request: what — a loan, petty cash or a certificate — and its details. The fields of each
 * (moneyFields(), certificateFields()) are those an approver can change too (EmployeeRequestActions::approve).
 */
class EmployeeRequestForm
{
    /** The choice that opens the leave request form instead (leave is its own request: LeaveRequest). */
    public const LEAVE = 'leave';

    public static function configure(Schema $schema): Schema
    {
        $type = fn (Get $get): ?EmployeeRequestType => $get('type') instanceof EmployeeRequestType ? $get('type') : EmployeeRequestType::tryFrom((string) $get('type'));

        return $schema->columns(1)->components([
            Section::make(__('Request'))
                ->columns(2)
                ->schema([
                    Select::make('party_id')
                        ->label(__('Employee'))
                        ->options(fn () => Party::withRole('employee')->orderBy('name')->pluck('name', 'id'))
                        ->default(fn () => auth()->user()?->party?->getKey())
                        ->searchable()
                        ->required()
                        // Their own, unless they file for others (who decides or pays them).
                        ->disabled(fn (): bool => ! auth()->user()?->can('manageOthers', EmployeeRequest::class))
                        ->dehydrated(),
                    ToggleButtons::make('type')
                        ->label(__('Request'))
                        ->options(fn (string $operation): array => [
                            ...collect(EmployeeRequestType::cases())->mapWithKeys(fn (EmployeeRequestType $type): array => [$type->value => $type->getLabel()])->all(),
                            // Leave too, from here: its own form opens (its balance, its approval).
                            ...($operation === 'create' && LeaveRequestResource::canCreate() ? [self::LEAVE => __('Vacation')] : []),
                        ])
                        ->icons(fn (): array => [
                            ...collect(EmployeeRequestType::cases())->mapWithKeys(fn (EmployeeRequestType $type): array => [$type->value => $type->getIcon()])->all(),
                            self::LEAVE => Heroicon::OutlinedSun,
                        ])
                        ->colors(fn (): array => [
                            ...collect(EmployeeRequestType::cases())->mapWithKeys(fn (EmployeeRequestType $type): array => [$type->value => $type->getColor()])->all(),
                            self::LEAVE => 'primary',
                        ])
                        ->default(EmployeeRequestType::LOAN->value)
                        ->inline()
                        ->required()
                        ->live()
                        ->afterStateUpdated(function (mixed $state, $livewire): void {
                            if ($state === self::LEAVE) {
                                $livewire->redirect(LeaveRequestResource::getUrl('create'), navigate: true);
                            }
                        }),
                ]),
            Section::make(__('Amount and repayment'))
                ->columns(3)
                ->visible(fn (Get $get): bool => (bool) $type($get)?->isRepaid())
                ->schema(self::moneyFields()),
            Section::make(__('Flight ticket'))
                ->columns(2)
                ->visible(fn (Get $get): bool => $type($get) === EmployeeRequestType::TICKET_ADVANCE)
                ->schema(self::ticketFields()),
            Section::make(__('Certificate'))
                ->columns(2)
                ->visible(fn (Get $get): bool => $type($get) === EmployeeRequestType::CERTIFICATE)
                ->schema(self::certificateFields()),
            Textarea::make('reason')
                ->label(__('Reason'))
                ->rows(2),
        ]);
    }

    /**
     * What is saved: only the fields of its kind of request, the month its first day; the employee one's own
     * unless they file for others (a disabled field still arrives in the request, so it is enforced here).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function clean(array $data): array
    {
        $type = $data['type'] instanceof EmployeeRequestType ? $data['type'] : EmployeeRequestType::from((string) $data['type']);
        $none = match (true) {
            $type->isRepaid() => ['letter_template_id', 'addressed_to', 'purpose', 'ticket_year'],
            $type === EmployeeRequestType::TICKET_ADVANCE => ['letter_template_id', 'addressed_to', 'purpose', 'months', 'starts_on'],
            default => ['amount', 'months', 'starts_on', 'ticket_year'],
        };

        foreach ($none as $field) {
            $data[$field] = null;
        }

        if (filled($data['starts_on'] ?? null)) {
            $data['starts_on'] = Carbon::parse($data['starts_on'])->startOfMonth()->toDateString();
        }

        if (! auth()->user()?->can('manageOthers', EmployeeRequest::class)) {
            $data['party_id'] = auth()->user()?->party?->getKey()
                ?? throw ValidationException::withMessages(['data.party_id' => __('Your user account is not linked to an employee record, so you cannot file requests.')]);
        }

        // A ticket: for what that year's ticket is, unless an amount was given.
        if ($type === EmployeeRequestType::TICKET_ADVANCE && blank($data['amount'] ?? null) && filled($data['ticket_year'] ?? null)) {
            $data['amount'] = FlightTicketService::amountFor((int) $data['party_id'], (int) $data['ticket_year']);
        }

        if ($error = EmployeeRequestService::beyondLimit($type, (int) $data['party_id'], (float) ($data['amount'] ?? 0), (int) ($data['months'] ?? 1), $data['ticket_year'] ?? null)) {
            throw ValidationException::withMessages(['data.'.($type === EmployeeRequestType::TICKET_ADVANCE ? 'ticket_year' : 'amount') => $error]);
        }

        return $data;
    }

    /**
     * How much, over how many months, from which month's salary — and the instalments that makes.
     *
     * @return array<int, mixed>
     */
    public static function moneyFields(): array
    {
        return [
            TextInput::make('amount')
                ->label(Currency::label(__('Amount (AED)')))
                ->numeric()
                ->minValue(1)
                ->required()
                ->live(onBlur: true),
            TextInput::make('months')
                ->label(__('Months'))
                ->helperText(__('How many months it is paid back over.'))
                ->numeric()
                ->integer()
                ->minValue(1)
                ->maxValue(60)
                ->default(1)
                ->required()
                ->live(onBlur: true),
            Select::make('starts_on')
                ->label(__('First deduction from the salary of'))
                ->options(fn ($state): array => self::months($state))
                ->default(fn (): string => now()->addMonth()->startOfMonth()->toDateString())
                ->formatStateUsing(fn ($state): ?string => filled($state) ? Carbon::parse($state)->startOfMonth()->toDateString() : null)
                ->required(),
            TextEntry::make('instalments')
                ->label(__('Instalments'))
                ->state(fn (Get $get) => EmployeeLoanForm::preview((float) $get('amount'), (int) $get('months')))
                ->columnSpanFull(),
            // A salary advance: what the salary is, which it may not go beyond over its months.
            TextEntry::make('monthly_salary')
                ->label(__('Monthly salary'))
                ->state(fn (Get $get, ?EmployeeRequest $record): string => number_format(EmployeeRequestService::monthlySalary((int) ($record?->party_id ?? $get('party_id'))), 2))
                ->visible(fn (Get $get, ?EmployeeRequest $record): bool => ($record?->type ?? EmployeeRequestType::tryFrom((string) ($get('type') instanceof EmployeeRequestType ? $get('type')->value : $get('type')))) === EmployeeRequestType::SALARY_ADVANCE)
                ->columnSpanFull(),
        ];
    }

    /**
     * Which year's flight ticket, and its amount — what that year's ticket is, filled in as the year is chosen.
     *
     * @return array<int, mixed>
     */
    public static function ticketFields(): array
    {
        $party = fn (Get $get, ?EmployeeRequest $record): int => (int) ($record?->party_id ?? $get('party_id'));

        return [
            Select::make('ticket_year')
                ->label(__('Ticket of the year'))
                ->options(fn (): array => collect([now()->year, now()->year + 1])->mapWithKeys(fn (int $year): array => [$year => (string) $year])->all())
                ->default(now()->year)
                ->required()
                ->live()
                ->afterStateUpdated(fn ($state, Get $get, Set $set, ?EmployeeRequest $record) => $set('amount', filled($state) ? FlightTicketService::amountFor($party($get, $record), (int) $state) : null)),
            TextInput::make('amount')
                ->label(Currency::label(__('Amount (AED)')))
                ->helperText(__('What the year\'s ticket is — paid now, it is not paid again with a salary.'))
                ->numeric()
                ->minValue(1)
                // Left empty: what the year's ticket is (EmployeeRequestForm::clean).
                ->default(fn (Get $get): ?float => filled($get('party_id')) ? FlightTicketService::amountFor((int) $get('party_id'), now()->year) : null),
        ];
    }

    /**
     * Which certificate, addressed to whom, what for.
     *
     * @return array<int, mixed>
     */
    public static function certificateFields(): array
    {
        return [
            Select::make('letter_template_id')
                ->label(__('Certificate'))
                ->options(fn () => LetterTemplate::query()->certificates()->orderBy('name')->pluck('name', 'id'))
                ->required(),
            TextInput::make('addressed_to')
                ->label(__('Addressed to'))
                ->placeholder(__('To whom it may concern'))
                ->required()
                ->maxLength(255),
            TextInput::make('purpose')
                ->label(__('Purpose'))
                ->maxLength(255)
                ->columnSpanFull(),
        ];
    }

    /**
     * This month and the next twelve: "October 2026" => its first day — and the one already chosen.
     *
     * @return array<string, string>
     */
    public static function months(mixed $chosen = null): array
    {
        return collect(range(0, 12))
            ->map(fn (int $i) => now()->startOfMonth()->addMonths($i))
            ->when(filled($chosen), fn ($months) => $months->prepend(Carbon::parse($chosen)->startOfMonth()))
            ->unique(fn (Carbon $month): string => $month->toDateString())
            ->sort()
            ->mapWithKeys(fn (Carbon $month): array => [$month->toDateString() => $month->translatedFormat('F Y')])
            ->all();
    }
}
