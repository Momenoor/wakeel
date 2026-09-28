<?php

namespace App\Filament\Pms\Resources\Quotations\Pages;

use App\Filament\Pms\Resources\Leases\LeaseResource;
use App\Filament\Pms\Resources\Quotations\QuotationResource;
use App\Models\Quotation;
use App\Services\PMS\QuotationService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use LogicException;
use RuntimeException;
use Throwable;

class ViewQuotation extends ViewRecord
{
    protected static string $resource = QuotationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()
                ->visible(fn (): bool => $this->quotation()->isEditable()),
            $this->sendAction(),
            $this->acceptAction(),
            $this->rejectAction(),
            $this->convertToLeaseAction(),
            $this->printAction(),
        ];
    }

    private function quotation(): Quotation
    {
        $record = $this->getRecord();

        if (! $record instanceof Quotation) {
            throw new LogicException('This page only shows a quotation.');
        }

        return $record;
    }

    private function sendAction(): Action
    {
        return Action::make('send')
            ->label(__('Send'))
            ->icon('heroicon-o-paper-airplane')
            ->color('info')
            ->requiresConfirmation()
            ->visible(fn (): bool => $this->quotation()->isDraft())
            ->action(fn () => $this->runQuotationStep(
                fn (QuotationService $service) => $service->send($this->quotation()),
                __('Quotation marked as sent.'),
            ));
    }

    private function acceptAction(): Action
    {
        return Action::make('accept')
            ->label(__('Accept'))
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->requiresConfirmation()
            ->visible(fn (): bool => $this->quotation()->isSent())
            ->action(fn () => $this->runQuotationStep(
                fn (QuotationService $service) => $service->accept($this->quotation()),
                __('Quotation accepted.'),
            ));
    }

    private function rejectAction(): Action
    {
        return Action::make('reject')
            ->label(__('Reject'))
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->requiresConfirmation()
            ->visible(fn (): bool => $this->quotation()->isSent())
            ->action(fn () => $this->runQuotationStep(
                fn (QuotationService $service) => $service->reject($this->quotation()),
                __('Quotation rejected.'),
            ));
    }

    /**
     * Opens the lease wizard filled in from this quotation — property,
     * units, tenant, period, rent, deposit and the instalments — for the
     * office to check and add cheque numbers and banks. Saving the lease
     * links it here and marks the quotation accepted.
     */
    private function convertToLeaseAction(): Action
    {
        return Action::make('convert_to_contract')
            ->label(__('Convert to Lease'))
            ->icon('heroicon-o-document-text')
            ->color('success')
            ->visible(fn (): bool => $this->quotation()->canConvertToLease())
            ->url(fn (): string => LeaseResource::getUrl('create', ['quotation' => $this->quotation()->getKey()]));
    }

    private function printAction(): Action
    {
        return Action::make('print')
            ->label(__('Print'))
            ->icon('heroicon-o-printer')
            ->color('gray')
            ->url(fn (): string => route('pms.quotations.print', $this->quotation()))
            ->openUrlInNewTab();
    }

    private function runQuotationStep(callable $step, string $success): void
    {
        try {
            $step(app(QuotationService::class));
        } catch (Throwable $exception) {
            Notification::make()
                ->danger()
                ->title(__('Could not continue'))
                ->body($exception instanceof RuntimeException ? $exception->getMessage() : __('Something went wrong.'))
                ->send();

            return;
        }

        Notification::make()->success()->title($success)->send();
    }
}
