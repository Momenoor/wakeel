<?php

namespace App\Filament\Mms\Resources\EmployeeRequests\Schemas;

use App\Filament\Mms\Resources\EmployeeLoans\EmployeeLoanResource;
use App\Filament\Support\AttachmentList;
use App\Models\EmployeeRequest;
use App\Services\MMS\EmployeeRequestService;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * An employee request's page: what was asked (as approved, when changed), the decision, the payment and the
 * advance it became, the certificate issued.
 */
class EmployeeRequestInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Section::make(__('Request'))
                // The whole width until there is a decision beside it.
                ->columnSpan(fn (EmployeeRequest $record): int|string => $record->isPending() ? 'full' : 1)
                ->schema([
                    TextEntry::make('rows')
                        ->hiddenLabel()
                        ->state(fn (EmployeeRequest $record): array => collect(EmployeeRequestService::rows($record))->map(fn (string $value, string $label): string => $label.': '.$value)->values()->all())
                        ->listWithLineBreaks(),
                    TextEntry::make('status')->label(__('Status'))->badge(),
                ]),
            Grid::make(1)->schema([
                Section::make(__('Decision'))
                    ->visible(fn (EmployeeRequest $record): bool => ! $record->isPending())
                    ->columns(2)
                    ->schema([
                        TextEntry::make('approver.display_name')->label(__('Decided by'))->placeholder(__('By email')),
                        TextEntry::make('approved_at')->label(__('On'))->dateTime(),
                        TextEntry::make('approved_comment')->label(__('Note'))->placeholder('—')->columnSpanFull(),
                    ]),
                Section::make(__('Disbursement'))
                    ->visible(fn (EmployeeRequest $record): bool => (bool) $record->type?->isMoney() && ! $record->isPending())
                    ->columns(2)
                    ->schema([
                        TextEntry::make('paid_on')->label(__('Paid on'))->date()->placeholder(__('Not paid yet')),
                        TextEntry::make('payer.display_name')->label(__('Marked paid by'))->placeholder('—'),
                        TextEntry::make('payment_note')->label(__('Note'))->placeholder('—'),
                        TextEntry::make('loan')
                            ->label(__('Advance'))
                            ->state(fn (EmployeeRequest $record): ?string => $record->employee_loan_id ? __('Loans & Petty Cash').' #'.$record->employee_loan_id : null)
                            ->url(fn (EmployeeRequest $record): ?string => $record->employee_loan_id && auth()->user()?->can('view', $record->loan) ? EmployeeLoanResource::getUrl('view', ['record' => $record->employee_loan_id]) : null)
                            ->placeholder('—'),
                    ]),
                Section::make(__('Certificate'))
                    ->visible(fn (EmployeeRequest $record): bool => $record->ownedAttachments->isNotEmpty())
                    ->schema([
                        TextEntry::make('reference')->label(__('Reference number'))->placeholder('—'),
                        AttachmentList::make()->label(__('Certificate')),
                    ]),
            ])->columnSpan(1),
        ]);
    }
}
