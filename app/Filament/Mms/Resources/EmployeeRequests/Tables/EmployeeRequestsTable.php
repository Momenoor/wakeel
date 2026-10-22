<?php

namespace App\Filament\Mms\Resources\EmployeeRequests\Tables;

use App\Enums\EmployeeRequestType;
use App\Enums\RequestStatus;
use App\Filament\Mms\Resources\EmployeeRequests\EmployeeRequestActions;
use App\Models\EmployeeRequest;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class EmployeeRequestsTable
{
    public static function configure(Table $table): Table
    {
        $managesOthers = fn (): bool => (bool) auth()->user()?->can('manageOthers', EmployeeRequest::class);

        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['party:id,name', 'template:id,name', 'approver:id,name,display_name']))
            ->columns([
                TextColumn::make('party.name')
                    ->label(__('Employee'))
                    ->searchable()
                    ->visible($managesOthers),
                TextColumn::make('type')
                    ->label(__('Request'))
                    ->badge(),
                // What was asked: the money and its months, or the certificate and whom it is to.
                TextColumn::make('details')
                    ->label(__('Details'))
                    ->state(fn (EmployeeRequest $record): string => $record->type?->isMoney()
                        ? __(':amount AED over :months months, from :month', ['amount' => number_format((float) $record->amount, 2), 'months' => $record->months, 'month' => $record->starts_on?->translatedFormat('F Y')])
                        : trim($record->template?->name.' — '.$record->addressed_to, ' —'))
                    ->wrap(),
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge(),
                // Money: whether the accountant paid it yet.
                TextColumn::make('paid_on')
                    ->label(__('Paid on'))
                    ->date()
                    ->placeholder(fn (EmployeeRequest $record): ?string => $record->awaitsPayment() ? __('Not paid yet') : '—'),
                TextColumn::make('approver.display_name')
                    ->label(__('Decided by'))
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->label(__('Requested on'))
                    ->date()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('type')->label(__('Request'))->options(EmployeeRequestType::class),
                SelectFilter::make('status')->label(__('Status'))->options(collect([RequestStatus::PENDING, RequestStatus::APPROVED, RequestStatus::REJECTED])->mapWithKeys(fn (RequestStatus $s): array => [$s->value => $s->getLabel()])->all()),
                Filter::make('to_pay')->label(__('Approved, not paid yet'))->query(fn (Builder $query): Builder => $query->awaitingPayment()),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                ViewAction::make(),
                ...EmployeeRequestActions::forList(),
                ActionGroup::make([EditAction::make(), DeleteAction::make()]),
            ]);
    }
}
