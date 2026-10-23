<?php

namespace App\Services\MMS\Requests;

use App\Enums\NotificationChannel;
use App\Enums\NotificationType;
use App\Enums\RequestStatus;
use App\Mail\NewRequestNotificationMail;
use App\Models\Matter;
use App\Models\User;
use App\Services\Notifications\NotificationPreferences;
use App\Services\Notifications\Notifier;
use App\Support\Dates;
use Carbon\Carbon;
use Filament\Forms\Components\DatePicker;

class ChangeDistributedAtRequestService extends BaseRequestService
{
    public static function createFormFields(): array
    {
        return [
            DatePicker::make('proposed_distributed_at')
                ->label(__('Proposed Assistant Assigning Date'))
                ->required(),
        ];
    }

    public static function prepareForCreation(array $data, Matter $matter): array
    {
        return [
            'comment' => $data['comment'] ?? null,
            'extra' => ! empty($data['proposed_distributed_at'])
                ? ['proposed_distributed_at' => $data['proposed_distributed_at']]
                : null,
        ];
    }

    public function canBeApproved(User $user): bool
    {
        // Disputed by the assistant (from their email): decided by whoever decides requests, not by the
        // assistant themselves — approving takes their proposed date, rejecting keeps the one set.
        if ($this->request->status === RequestStatus::DISPUTED) {
            return $user->can('Update:MatterRequest');
        }

        // Update:MatterRequest rather than a role check — the sibling
        // BaseRequestService::canBeActedOnBy() already treats that permission
        // as the elevated-approval ability for ordinary requests, and Shield's
        // Gate::before makes it true unconditionally for super-admins, so this
        // stays behaviourally identical to the hardcoded role check it replaces
        // while using the same permission both classes now agree on.
        return $this->request->status === RequestStatus::PENDING
            && (auth()->id() === $this->request->request_by || $user->can('Update:MatterRequest'));
    }

    public function canBeRejected(User $user): bool
    {
        return $this->canBeApproved($user);
    }

    public function approve(array $data = [], $component = null): void
    {
        $this->markApproved($data);

        // Apply the proposed received date to the matter
        $proposedDate = $this->request->extra['proposed_distributed_at'] ?? null;
        if ($proposedDate) {
            $this->request->matter->update([
                'distributed_at' => $proposedDate,
            ]);
        }

        $this->onApproveNotify();
        $this->refresh($component);
    }

    /** The assistant disputed the received date: the admins are told, to decide it. */
    public function onDisputeNotify(): void
    {
        $users = self::admins();
        $type = NotificationType::REQUEST_CREATED;
        $title = __('Received date disputed');
        $body = __('The assistant disputed the received date of matter #:number / :year and proposes :date.', [
            'number' => $this->request->matter->number,
            'year' => $this->request->matter->year,
            'date' => Carbon::parse($this->request->extra['proposed_distributed_at'])->format(Dates::dateFormat()),
        ]);

        $this->notify($type, $title, $body, $users);
        Notifier::whatsapp(NotificationPreferences::wanting($type, $users, NotificationChannel::WHATSAPP), $title, $body, $this->requestUrl());
        $this->mailEach($type, $users, fn () => new NewRequestNotificationMail($this->request->matter, $this->request));
    }

    public function reject(array $data = [], $component = null): void
    {
        $this->markRejected($data);

        // Notify the assistant of rejection with reason
        $this->onRejectNotify();

        $this->refresh($component);
    }
}
