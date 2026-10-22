<?php

namespace App\Services\MMS;

use App\Enums\AttachmentCategory;
use App\Enums\LoanStatus;
use App\Enums\NotificationChannel;
use App\Enums\NotificationType;
use App\Enums\RequestStatus;
use App\Filament\Mms\Resources\EmployeeRequests\EmployeeRequestResource;
use App\Mail\EmployeeRequestDecisionMail;
use App\Models\Attachment;
use App\Models\EmployeeLoan;
use App\Models\EmployeeRequest;
use App\Models\User;
use App\Services\Attachments\AttachmentService;
use App\Services\MMS\Letters\LetterComposer;
use App\Services\MMS\Letters\LetterPdf;
use App\Services\Notifications\NotificationPreferences;
use App\Services\Notifications\Notifier;
use App\Support\Addresses;
use BezhanSalleh\FilamentShield\Support\Utils;
use Carbon\CarbonInterface;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Throwable;

/**
 * An employee request decided and carried through (EmployeeRequest): approved — as asked, or with what the
 * approver changed — or rejected; an approved certificate issued from its template at once; approved money
 * paid by the accountant, then made the advance payroll deducts from (EmployeeLoan, its schedule). The
 * employee hears of each step; the accountant of money to pay.
 */
class EmployeeRequestService
{
    /** Who decides them: leave's approvers (HrRecipients). */
    public const APPROVE = 'Approve:EmployeeRequest';

    /** Who confirms the money paid. */
    public const PAY = 'Pay:EmployeeRequest';

    /** What an approver may change of what was asked. */
    public const CHANGEABLE = ['amount', 'months', 'starts_on', 'letter_template_id', 'addressed_to', 'purpose'];

    public function __construct(private LoanScheduleService $schedule) {}

    /**
     * @param  array<string, mixed>  $changes  of CHANGEABLE
     */
    public function approve(EmployeeRequest $request, ?User $approver, array $changes = [], ?string $comment = null): EmployeeRequest
    {
        $this->assertPending($request);

        DB::transaction(function () use ($request, $approver, $changes, $comment): void {
            $changes = Arr::only($changes, self::CHANGEABLE);

            if (filled($changes['starts_on'] ?? null)) {
                $changes['starts_on'] = Carbon::parse($changes['starts_on'])->startOfMonth();
            }

            $request->fill($changes)->forceFill([
                'status' => RequestStatus::APPROVED,
                'approved_by' => $approver?->getKey(),
                'approved_at' => now(),
                'approved_comment' => $comment,
            ])->save();
        });

        if ($request->type?->isMoney()) {
            $this->tellPayers($request);
        } else {
            // Issued at once; if it cannot be, it is approved still — issued again from its page.
            rescue(fn () => $this->issue($request));
        }

        $this->tellRequester($request->refresh(), approved: true);

        return $request;
    }

    public function reject(EmployeeRequest $request, ?User $approver, string $reason): EmployeeRequest
    {
        $this->assertPending($request);

        $request->forceFill([
            'status' => RequestStatus::REJECTED,
            'approved_by' => $approver?->getKey(),
            'approved_at' => now(),
            'approved_comment' => $reason,
        ])->save();

        $this->tellRequester($request, approved: false);

        return $request;
    }

    /**
     * The money handed to the employee: the advance made, its instalments from the month approved — payroll
     * deducts them from then on.
     */
    public function markPaid(EmployeeRequest $request, User $by, CarbonInterface $on, ?string $note = null): EmployeeLoan
    {
        if (! $request->awaitsPayment()) {
            throw new RuntimeException(__('Only an approved loan or petty cash not yet paid can be marked paid.'));
        }

        $loan = DB::transaction(function () use ($request, $by, $on, $note): EmployeeLoan {
            $loan = EmployeeLoan::create([
                'party_id' => $request->party_id,
                'kind' => $request->type->loanKind(),
                'principal' => $request->amount,
                'months' => $request->months,
                'starts_on' => $request->starts_on,
                'status' => LoanStatus::ACTIVE,
                'notes' => trim(__('Employee request #:id', ['id' => $request->getKey()]).($request->reason ? ' — '.$request->reason : '').($note ? ' — '.$note : '')),
            ]);
            $loan->forceFill(['approved_by' => $request->approved_by, 'approved_at' => $request->approved_at])->save();
            $this->schedule->generateFor($loan);

            $request->forceFill([
                'paid_on' => $on,
                'paid_by' => $by->getKey(),
                'payment_note' => $note,
                'employee_loan_id' => $loan->getKey(),
            ])->save();

            return $loan;
        });

        if ($user = $request->party?->user) {
            Notifier::send(
                NotificationType::EMPLOYEE_REQUEST_DECIDED,
                [$user],
                __(':type paid', ['type' => $request->type->getLabel()]),
                __(':amount AED paid on :date; deducted from your salary over :months months from :month.', [
                    'amount' => number_format((float) $request->amount, 2),
                    'date' => $on->format('d/m/Y'),
                    'months' => $request->months,
                    'month' => $request->starts_on?->format('m/Y'),
                ]),
                self::url($request, $user),
                fn (Notification $n) => $n->status('success')->iconColor('success'),
                [NotificationChannel::BELL, NotificationChannel::WHATSAPP],
            );
        }

        return $loan;
    }

    /** The certificate issued from its template (again: in place of the one before), kept as its file. */
    public function issue(EmployeeRequest $request): Attachment
    {
        $template = $request->template ?? throw new RuntimeException(__('Choose the certificate to issue.'));
        $reference = 'HR/'.now()->format('Y').'/'.str_pad((string) $request->getKey(), 4, '0', STR_PAD_LEFT);

        $composer = new LetterComposer($template, null, reference: $reference, extra: EmployeePlaceholders::values($request));
        $pdf = (new LetterPdf($composer))->render();

        $request->ownedAttachments()->where('category', AttachmentCategory::CERTIFICATE->value)->get()->each->forceDelete();
        $attachment = app(AttachmentService::class)->store(
            $request,
            $pdf,
            AttachmentCategory::CERTIFICATE,
            $template->name.' — '.$request->party?->name.'.pdf',
            $request->approver,
            source: 'system',
            mime: 'application/pdf',
        );

        $request->forceFill(['reference' => $reference])->save();

        return $attachment;
    }

    /**
     * What a request is, as its emails and pages show it.
     *
     * @return array<string, string>
     */
    public static function rows(EmployeeRequest $request): array
    {
        $money = $request->type?->isMoney();

        return array_filter([
            __('Employee') => (string) $request->party?->name,
            __('Request') => (string) $request->type?->getLabel(),
            __('Amount (AED)') => $money ? number_format((float) $request->amount, 2) : null,
            __('Months') => $money ? (string) $request->months : null,
            __('First deduction from the salary of') => $money ? (string) $request->starts_on?->locale('ar')->translatedFormat('F Y') : null,
            __('Instalments') => $money ? self::plan($request) : null,
            __('Certificate') => $money ? null : (string) $request->template?->name,
            __('Addressed to') => $money ? null : (string) $request->addressed_to,
            __('Purpose') => $money ? null : (string) $request->purpose,
            __('Reason') => (string) $request->reason,
        ], fn (?string $value): bool => filled($value));
    }

    /** "1,000.00 the first month, then 500.00 for 3 months". */
    public static function plan(EmployeeRequest $request): string
    {
        if ((float) $request->amount <= 0 || (int) $request->months < 1) {
            return '';
        }

        $schedule = app(LoanScheduleService::class)->plan((float) $request->amount, (int) $request->months, ($request->starts_on ?? now())->format('Y-m'));

        return count($schedule) === 1
            ? number_format($schedule[0]['amount'], 2)
            : __(':first the first month, then :rest for :count months', ['first' => number_format($schedule[0]['amount'], 2), 'rest' => number_format($schedule[1]['amount'], 2), 'count' => count($schedule) - 1]);
    }

    /** Its page, for whoever may open it; the list otherwise. */
    public static function url(EmployeeRequest $request, ?User $user = null): string
    {
        return $user?->can('view', $request)
            ? EmployeeRequestResource::getUrl('view', ['record' => $request], panel: 'mms')
            : EmployeeRequestResource::getUrl('index', panel: 'mms');
    }

    private function assertPending(EmployeeRequest $request): void
    {
        if (! $request->isPending()) {
            throw new RuntimeException(__('This request was already decided.'));
        }
    }

    /** The employee hears the decision — the bell (WhatsApp, as they chose) and the email, the office copied in. */
    private function tellRequester(EmployeeRequest $request, bool $approved): void
    {
        $user = $request->party?->user;
        $type = NotificationType::EMPLOYEE_REQUEST_DECIDED;
        $status = $approved ? 'success' : 'danger';

        if ($user) {
            Notifier::send(
                $type,
                [$user],
                $approved ? __(':type request approved', ['type' => $request->type->getLabel()]) : __(':type request rejected', ['type' => $request->type->getLabel()]),
                $approved
                    ? ($request->type->isMoney() ? __('It is paid to you soon; you are told when it is.') : __('Your certificate is ready to download.'))
                    : __('Reason: :reason', ['reason' => (string) $request->approved_comment]),
                self::url($request, $user),
                fn (Notification $n) => $n->status($status)->iconColor($status),
                [NotificationChannel::BELL, NotificationChannel::WHATSAPP],
            );
        }

        $employee = match (true) {
            $user === null => Addresses::emails((array) ($request->party?->email ?? [])),
            NotificationPreferences::wants($user, $type, NotificationChannel::MAIL) => Addresses::emails(filled($request->party?->email) ? (array) $request->party->email : [$user->email]),
            default => [],
        };
        $office = Addresses::without(HrRecipients::mailed(self::APPROVE, NotificationType::EMPLOYEE_REQUEST_SUBMITTED), $employee);

        if ($employee === [] && $office === []) {
            return;
        }

        try {
            Mail::to($employee !== [] ? $employee : $office)
                ->cc($employee !== [] ? $office : [])
                ->locale('ar')
                ->queue(new EmployeeRequestDecisionMail($request));
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** Approved money: the accountants hear it is to be paid. */
    private function tellPayers(EmployeeRequest $request): void
    {
        $payers = User::query()
            ->where(fn (Builder $q) => $q
                ->whereHas('roles', fn (Builder $role) => $role->where('name', Utils::getSuperAdminName())
                    ->orWhereHas('permissions', fn (Builder $p) => $p->where('name', self::PAY)))
                ->orWhereHas('permissions', fn (Builder $p) => $p->where('name', self::PAY)))
            ->get();

        Notifier::send(
            NotificationType::EMPLOYEE_REQUEST_TO_PAY,
            $payers,
            __(':type to pay', ['type' => $request->type->getLabel()]),
            __(':name — :amount AED, approved. Mark it paid once handed over.', ['name' => (string) $request->party?->name, 'amount' => number_format((float) $request->amount, 2)]),
            EmployeeRequestResource::getUrl('view', ['record' => $request], panel: 'mms'),
        );
    }
}
