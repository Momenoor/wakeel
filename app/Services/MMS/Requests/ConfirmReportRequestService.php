<?php

namespace App\Services\MMS\Requests;

use Filament\Forms\Components\Toggle;

class ConfirmReportRequestService extends BaseRequestService
{
    public static function supportsChanges(): bool
    {
        return true;
    }

    public static function requiresAttachmentsOnCreate(): bool
    {
        return true;
    }

    /** The final report as a Word file among them, beside any other (a PDF, its annexes). */
    public static function requiresWordFile(): bool
    {
        return true;
    }

    public static function approvalFormFields(): array
    {
        return [
            Toggle::make('has_substantive_changes')
                ->label(__('Has Substantive Changes'))
                ->default(false),
        ];
    }

    public static function rejectionRequiresAttachments(): bool
    {
        return true;
    }

    public function approve(array $data = [], $component = null): void
    {
        $this->markApproved($data);

        $matter = $this->request->matter;

        if ($matter->type?->final_report_only) {
            // The type's matters have this one report: the initial report (if not there yet), the final
            // report and its memo date together — and what the review of an initial report would have said.
            $matter->final_report_memo_date = now();
            $matter->has_substantive_changes = $data['has_substantive_changes'] ?? false;
            $matter->finalReportSubmission();
        } else {
            $matter->update([
                'final_report_memo_date' => now(),
            ]);
        }

        $this->onApproveNotify();
        $this->refresh($component);
    }

    public function reject(array $data = [], $component = null): void
    {
        $this->markRejected($data);

        // Notify the assistant of rejection with reason
        $this->onRejectNotify();

        $this->refresh($component);
    }
}
