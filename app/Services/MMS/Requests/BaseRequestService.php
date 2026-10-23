<?php

namespace App\Services\MMS\Requests;

use App\Enums\AttachmentCategory;
use App\Enums\NotificationChannel;
use App\Enums\NotificationType;
use App\Enums\RequestStatus;
use App\Enums\RequestType;
use App\Filament\Support\AttachmentFields;
use App\Mail\NewRequestNotificationMail;
use App\Mail\RequestActionNotificationMail;
use App\Models\Matter;
use App\Models\MatterRequest;
use App\Models\User;
use App\Services\MMS\MatterPeople;
use App\Services\Notifications\NotificationPreferences;
use App\Services\Notifications\Notifier;
use App\Services\WhatsAppService;
use BezhanSalleh\FilamentShield\Support\Utils;
use Closure;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;

abstract class BaseRequestService
{
    public function __construct(protected MatterRequest $request) {}

    abstract public function approve(array $data = [], $component = null): void;

    abstract public function reject(array $data = [], $component = null): void;

    /**
     * Extra Filament form components shown in the "Add Request" modal once this type is selected.
     *
     * @return array<Component>
     */
    public static function createFormFields(): array
    {
        return [];
    }

    public static function requiresAttachmentsOnCreate(): bool
    {
        return false;
    }

    /**
     * Build the comment/extra payload to store on the new MatterRequest row.
     *
     * @return array{comment: ?string, extra: ?array}
     */
    public static function prepareForCreation(array $data, Matter $matter): array
    {
        return [
            'comment' => $data['comment'] ?? null,
            'extra' => null,
        ];
    }

    /**
     * Hook run right after the MatterRequest row (and its attachments) have been created.
     */
    public function afterCreated(): void
    {
        //
    }

    /**
     * Extra Filament form components shown in the "Approve" modal for this type.
     *
     * @return array<Component>
     */
    public static function approvalFormFields(): array
    {
        return [];
    }

    public static function rejectionRequiresAttachments(): bool
    {
        return false;
    }

    public function canBeApproved(User $user): bool
    {
        return $this->canBeActedOnBy($user, 'ApproveRequest:Matter');
    }

    public function canBeRejected(User $user): bool
    {
        return $this->canBeActedOnBy($user, 'RejectRequest:Matter');
    }

    private function canBeActedOnBy(User $user, string $matterPermission): bool
    {
        // No role check needed here: Shield's Gate::before already makes both
        // of these can() calls true unconditionally for the super-admin role,
        // so a hardcoded hasAnyRole(['super-admin', 'super_admin']) fallback
        // would only ever fire for a case these two permissions already cover.
        if (! ($user->can('Update:MatterRequest') || $user->can($matterPermission))) {
            return false;
        }

        return $this->request->status === RequestStatus::DISPUTED
            || ($this->request->status === RequestStatus::PENDING && $this->request->type !== RequestType::CHANGE_DISTRIBUTED_DATE)
            || ($this->request->status === RequestStatus::PENDING && auth()->id() === $this->request->request_by);
    }

    /** Whether a reviewer can send this type back for changes (reports). */
    public static function supportsChanges(): bool
    {
        return false;
    }

    /**
     * The reviewer wants changes: the request goes back to the assistant with what to change (their
     * text and files), and it counts as a review of the matter. Not rejected — the assistant sends the
     * corrected report on the same request (resubmit()).
     *
     * @param  array{approved_comment?: ?string, attachments?: array}  $data
     */
    public function requestChanges(array $data = [], $component = null): void
    {
        $this->request->update([
            'status' => RequestStatus::NEEDS_CHANGES,
            'approved_by' => auth()->id(),
            'approved_at' => now(),
            'approved_comment' => $data['approved_comment'] ?? null,
        ]);
        $this->storeFile($data['attachments'] ?? []);

        $this->request->matter->increment('review_count');
        $this->request->update(['extra' => [...(array) $this->request->extra, 'modifications' => (int) ($this->request->extra['modifications'] ?? 0) + 1]]);

        $this->onActionNotify(
            NotificationType::REQUEST_CHANGES,
            __('Changes requested'),
            __('Changes were requested on your request. Details: :reason', ['reason' => $this->request->approved_comment])
        );
        $this->refresh($component);
    }

    /**
     * The assistant's corrected report, sent for review again on the same request: back to pending, its
     * files added, the reviewers told (on WhatsApp too). What was asked is kept in its history.
     *
     * @param  array{comment?: ?string, attachments?: array}  $data
     */
    public function resubmit(array $data = [], $component = null): void
    {
        $extra = (array) $this->request->extra;
        $extra['rounds'] = [...($extra['rounds'] ?? []), [
            'asked_at' => $this->request->approved_at?->toDateTimeString(),
            'asked_by' => $this->request->approved_by,
            'reason' => $this->request->approved_comment,
            // The files of the new round are those added after this one.
            'attachments_from' => (int) $this->request->attachments()->max('id'),
        ]];

        $this->request->update([
            'status' => RequestStatus::PENDING,
            'approved_by' => null,
            'approved_at' => null,
            'approved_comment' => null,
            'comment' => filled($data['comment'] ?? null) ? $data['comment'] : $this->request->comment,
            'extra' => $extra,
        ]);
        $this->storeFile($data['attachments'] ?? []);

        $this->onCreateNotify(again: true);
        $this->refresh($component);
    }

    protected function markApproved(array $data): void
    {
        $this->request->update([
            'status' => 'approved',
            'approved_by' => auth()->id(),
            'approved_at' => now(),
            'approved_comment' => $data['approved_comment'] ?? null,
        ]);

        $this->storeFile($data['attachments']);
    }

    protected function markRejected(array $data): void
    {

        $this->request->update([
            'status' => 'rejected',
            'approved_by' => auth()->id(),
            'approved_at' => now(),
            'approved_comment' => $data['approved_comment'],
        ]);
        $this->storeFile($data['attachments']);
    }

    public function refresh($component): void
    {
        $this->request->refresh();
        $this->request->unsetRelation('matter');
        if ($component) {
            $livewire = $component->getLivewire();
            $livewire->dispatch('$refresh');

            // Refresh the parent matter record if available
            if (method_exists($livewire, 'getRecord') && $livewire->getRecord()) {
                $livewire->getRecord()->refresh();
                $livewire->getRecord()->unsetRelation('requests');
            }
        }
    }

    /**
     * The bell, to those of them who want it so (the "View" button is kept untranslated, so each
     * reader sees it in their own language when shown — AppServiceProvider).
     *
     * @param  Collection<int, User>  $recipients
     */
    protected function notify(NotificationType $type, string $title, string $body, Collection $recipients): void
    {
        Notifier::bell(
            $type,
            NotificationPreferences::wanting($type, $recipients, NotificationChannel::BELL),
            $title,
            $body,
            $this->requestUrl(),
            fn (Notification $notification) => $notification->icon('heroicon-o-clipboard-document-list'),
        );
    }

    protected function requestUrl(): string
    {
        return route('filament.mms.resources.matter-requests.view', $this->request);
    }

    /**
     * The request's own email, one to each of those who want it so (never one with everybody in To:);
     * one that cannot be sent does not stop the others.
     *
     * @param  Collection<int, User>  $recipients
     * @param  Closure(): Mailable  $mail
     */
    protected function mailEach(NotificationType $type, Collection $recipients, Closure $mail): void
    {
        $wanting = NotificationPreferences::wanting($type, $recipients, NotificationChannel::MAIL)
            ->filter(fn (User $user): bool => filled($user->email));

        foreach ($wanting as $user) {
            try {
                Mail::to($user->email)->send($mail());
            } catch (\Throwable $e) {
                \Log::warning('Request email not sent: '.$e->getMessage(), ['user' => $user->getKey(), 'request' => $this->request->getKey()]);
            }
        }
    }

    /** Tells the matter's assistants (their users) what was decided on their request. */
    private function onActionNotify(NotificationType $type, string $title, string $body): void
    {
        $statusLabel = $this->request->status->getLabel();

        // The matter's assistants, and those who handle their matters with them.
        $assistants = MatterPeople::of([(int) $this->request->matter_id], assistantsOnly: true);

        $this->notify($type, $title, $body, $assistants);
        $this->mailEach($type, $assistants, fn () => new RequestActionNotificationMail($this->request->matter, $this->request, $statusLabel));
        Notifier::whatsapp(NotificationPreferences::wanting($type, $assistants, NotificationChannel::WHATSAPP), $title, $body, $this->requestUrl());
    }

    /**
     * Those told of a request to decide: the admins and super admins.
     *
     * @return Collection<int, User>
     */
    protected static function admins(): Collection
    {
        // By name rather than User::role(), which throws for a role an installation does not have.
        return User::query()->whereHas('roles', fn ($query) => $query->whereIn('name', ['admin', Utils::getSuperAdminName()]))->get();
    }

    public function onCreateNotify(bool $again = false): void
    {
        try {
            // Not an authorization decision — Gate::before has nothing to say
            // about "which users hold this role" — so this still names the
            // role, but through Shield's own configured name rather than a
            // hardcoded literal that has to be kept in sync with it by hand.
            $users = self::admins();

            // The assistant's reviewers (Parties → the assistant): the report on WhatsApp, to answer there. One who
            // got it is not also told of a new request, by bell, email or WhatsApp: it is the same thing.
            $reached = [];
            try {
                $reached = app(ReportReviewWhatsApp::class)->send($this->request)['reached'];
            } catch (\Throwable $e) {
                \Log::warning('Report not sent to its reviewers on WhatsApp: '.$e->getMessage(), ['request' => $this->request->getKey()]);
            }
            $users = $users->reject(fn (User $user): bool => in_array($user->getKey(), $reached, true))->values();

            if ($users->isEmpty()) {
                return;
            }

            $type = NotificationType::REQUEST_CREATED;

            $this->notify(
                $type,
                $again ? __('Request Resubmitted') : __('Request Created'),
                __($again ? 'The :type request for matter #:number / :year was corrected and sent again' : 'A new :type request has been created, for matter #:number / :year', [
                    'type' => $this->request->type->getLabel(),
                    'number' => $this->request->matter->number,
                    'year' => $this->request->matter->year,
                ]),
                $users
            );

            foreach (NotificationPreferences::wanting($type, $users, NotificationChannel::WHATSAPP) as $user) {
                try {
                    WhatsAppService::notifyNewRequest($user, $this->request);
                } catch (\Throwable $e) {
                    \Log::warning('New request not sent on WhatsApp: '.$e->getMessage(), ['user' => $user->getKey(), 'request' => $this->request->getKey()]);
                }
            }

            $this->mailEach($type, $users, fn () => new NewRequestNotificationMail($this->request->matter, $this->request));

        } catch (\Exception $e) {
            \Log::error('Failed to send notification: '.$e->getMessage());
        }

    }

    protected function onApproveNotify(): void
    {
        $this->onActionNotify(
            NotificationType::REQUEST_DECIDED,
            __('Request Approved'),
            filled($this->request->approved_comment)
                ? __('Your request has been approved. Comment: :comment', ['comment' => $this->request->approved_comment])
                : __('Your request has been approved.')
        );
    }

    protected function onRejectNotify(): void
    {
        $reason = $this->request->approved_comment;
        $this->onActionNotify(
            NotificationType::REQUEST_DECIDED,
            __('Request Rejected'),
            __('Your request has been rejected. Reason: :reason', ['reason' => $reason])
        );
    }

    /**
     * The request's files, kept on it: each is a path from the upload field (AttachmentFields::upload), or
     * ['path' => …, 'source' => 'whatsapp'] for a file already stored (WhatsApp's) — AttachmentFields::attach.
     */
    protected function storeFile($attachments): void
    {
        AttachmentFields::attach($this->request, (array) $attachments, AttachmentCategory::REQUEST);
    }
}
