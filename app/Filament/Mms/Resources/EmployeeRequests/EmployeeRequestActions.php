<?php

namespace App\Filament\Mms\Resources\EmployeeRequests;

use App\Enums\EmployeeRequestType;
use App\Enums\RequestStatus;
use App\Filament\Mms\Resources\EmployeeRequests\Schemas\EmployeeRequestForm;
use App\Models\EmployeeRequest;
use App\Services\MMS\EmployeeRequestService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * What is done to an employee request — on its list and its page alike: approve it (as asked, or changed),
 * reject it, confirm the money paid, issue its certificate again.
 */
class EmployeeRequestActions
{
    /** @return list<Action> on the list (issuing again is on its page: it looks for the certificate) */
    public static function forList(): array
    {
        return [self::approve(), self::reject(), self::markPaid()];
    }

    /** @return list<Action> on its page */
    public static function forPage(): array
    {
        return [...self::forList(), self::issueAgain()];
    }

    public static function approve(): Action
    {
        return Action::make('approve')
            ->label(__('Approve'))
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->authorize('approve')
            ->visible(fn (EmployeeRequest $record): bool => $record->isPending())
            ->modalHeading(fn (EmployeeRequest $record): string => __('Approve :type request', ['type' => $record->type->getLabel()]))
            ->modalDescription(__('As asked — or change it before approving.'))
            ->fillForm(fn (EmployeeRequest $record): array => [
                ...Arr::only($record->attributesToArray(), EmployeeRequestService::CHANGEABLE),
                'starts_on' => $record->starts_on?->toDateString(),
            ])
            ->schema(fn (EmployeeRequest $record): array => [
                ...match ($record->type) {
                    EmployeeRequestType::CERTIFICATE => EmployeeRequestForm::certificateFields(),
                    EmployeeRequestType::TICKET_ADVANCE => EmployeeRequestForm::ticketFields(),
                    default => EmployeeRequestForm::moneyFields(),
                },
                Textarea::make('approved_comment')->label(__('Note'))->rows(2)->columnSpanFull(),
            ])
            ->modalWidth('2xl')
            ->databaseTransaction(false)
            ->action(fn (EmployeeRequest $record, array $data) => self::attempt(
                fn () => app(EmployeeRequestService::class)->approve($record, auth()->user(), Arr::except($data, ['approved_comment']), $data['approved_comment'] ?? null),
                fn (): string => match (true) {
                    $record->type->isMoney() => __('Approved. The accountant is told to pay it.'),
                    $record->certificate() !== null => __('Approved, and its certificate issued.'),
                    default => __('Approved, but the certificate could not be issued: issue it again from its page.'),
                },
            ));
    }

    public static function reject(): Action
    {
        return Action::make('reject')
            ->label(__('Reject'))
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->authorize('approve')
            ->visible(fn (EmployeeRequest $record): bool => $record->isPending())
            ->schema([Textarea::make('approved_comment')->label(__('Reason'))->required()->rows(3)])
            ->action(fn (EmployeeRequest $record, array $data) => self::attempt(
                fn () => app(EmployeeRequestService::class)->reject($record, auth()->user(), $data['approved_comment']),
                __('Rejected.'),
            ));
    }

    /** The money handed over: it becomes the advance payroll deducts (Loans & petty cash). */
    public static function markPaid(): Action
    {
        return Action::make('markPaid')
            ->label(__('Mark paid'))
            ->icon(Heroicon::OutlinedBanknotes)
            ->color('warning')
            ->authorize('pay')
            ->visible(fn (EmployeeRequest $record): bool => $record->awaitsPayment())
            ->modalHeading(fn (EmployeeRequest $record): string => __('Paid to :name?', ['name' => (string) $record->party?->name]))
            ->modalDescription(fn (EmployeeRequest $record): string => collect(EmployeeRequestService::rows($record))->map(fn (string $value, string $label): string => $label.': '.$value)->implode(' · '))
            ->schema([
                DatePicker::make('paid_on')->label(__('Paid on'))->default(now())->maxDate(now())->required(),
                TextInput::make('payment_note')->label(__('Note'))->placeholder(__('Cash, transfer reference …'))->maxLength(255),
            ])
            ->modalSubmitActionLabel(__('Mark paid'))
            ->action(fn (EmployeeRequest $record, array $data) => self::attempt(
                fn () => app(EmployeeRequestService::class)->markPaid($record, auth()->user(), Carbon::parse($data['paid_on']), $data['payment_note'] ?? null),
                $record->type->isRepaid() ? __('Marked paid: its instalments are deducted from the salary from the month approved.') : __('Marked paid: the year\'s flight ticket is paid, and not paid again with a salary.'),
            ));
    }

    /** An approved certificate made again from its template (changed since, say). */
    public static function issueAgain(): Action
    {
        return Action::make('issueAgain')
            ->label(fn (EmployeeRequest $record): string => $record->certificate() ? __('Issue again') : __('Issue the certificate'))
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('gray')
            ->authorize('approve')
            ->visible(fn (EmployeeRequest $record): bool => $record->type === EmployeeRequestType::CERTIFICATE && $record->status === RequestStatus::APPROVED)
            ->requiresConfirmation()
            ->action(fn (EmployeeRequest $record) => self::attempt(fn () => app(EmployeeRequestService::class)->issue($record), __('Certificate issued.')));
    }

    /**
     * Done, said so; or why not.
     *
     * @param  string|callable(): string  $done
     */
    private static function attempt(callable $do, string|callable $done): void
    {
        try {
            $do();
            Notification::make()->success()->title(is_callable($done) ? $done() : $done)->send();
        } catch (Throwable $e) {
            report($e);
            Notification::make()->danger()->title(__('Not done'))->body($e->getMessage())->send();
        }
    }
}
