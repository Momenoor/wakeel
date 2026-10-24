<?php

namespace App\Enums;

use App\Models\User;
use App\Services\WhatsAppCloud;

/**
 * Everything the system tells a user, one case each: what it is, which channels it can use, and which
 * are on until someone chooses otherwise (what is sent today). Who receives one is decided where it is
 * sent (the matter's assistants, the approvers …); preferences — a role's defaults, then the user's own —
 * only say by which channels each of them is told (see NotificationPreferences).
 */
enum NotificationType: string
{
    // Requests
    case REQUEST_CREATED = 'request.created';
    case REQUEST_DECIDED = 'request.decided';
    case REQUEST_CHANGES = 'request.changes_requested';

    // Matters
    case MATTER_ASSIGNED = 'matter.assigned';
    case MATTER_REPLY_RECEIVED = 'matter.reply_received';
    case MATTER_EMAIL_RECEIVED = 'matter.email_received';
    case MATTER_EMAIL_UNFILED = 'matter.email_unfiled';
    case MATTER_EMAIL_SENT = 'matter.email_sent';
    case MATTER_EMAIL_FORWARDED = 'matter.email_forwarded';
    case MINUTES_REPLY = 'minutes.reply_received';
    case ONEDRIVE_FOLDER_REVIEW = 'onedrive.folder_review';

    // Leave
    case LEAVE_SUBMITTED = 'leave.submitted';
    case LEAVE_DECIDED = 'leave.decided';

    // Employee requests (loans, petty cash, certificates)
    case EMPLOYEE_REQUEST_SUBMITTED = 'employee_request.submitted';
    case EMPLOYEE_REQUEST_DECIDED = 'employee_request.decided';
    case EMPLOYEE_REQUEST_TO_PAY = 'employee_request.to_pay';

    // Chat and to-dos
    case CHAT_MESSAGE = 'chat.message';
    case CHAT_REACTION = 'chat.reaction';
    case TODO_MENTIONED = 'todo.mentioned';
    case TODO_COMPLETED = 'todo.completed';

    // Calendar
    case CALENDAR_UNMATCHED = 'calendar.unmatched';
    case CALENDAR_REMINDER = 'calendar.reminder';

    // Fees
    case FEES_CHANGED = 'fees.changed';

    public function label(): string
    {
        return match ($this) {
            self::REQUEST_CREATED => __('A new request, or one sent for review again'),
            self::REQUEST_DECIDED => __('A request of mine was approved or rejected'),
            self::REQUEST_CHANGES => __('Changes were requested on my report'),
            self::MATTER_ASSIGNED => __('A matter was assigned to me'),
            self::MATTER_REPLY_RECEIVED => __('A reply to an email I sent'),
            self::MATTER_EMAIL_RECEIVED => __('An email from a matter\'s party was kept'),
            self::MATTER_EMAIL_UNFILED => __('An email waiting for its matter to be chosen'),
            self::MATTER_EMAIL_SENT => __('Someone else sent an email on my matter'),
            self::MATTER_EMAIL_FORWARDED => __('An email I forwarded to the filing mailbox was not filed'),
            self::MINUTES_REPLY => __('A reply to minutes I sent for signature'),
            self::ONEDRIVE_FOLDER_REVIEW => __('A OneDrive folder to review'),
            self::LEAVE_SUBMITTED => __('A leave request to decide'),
            self::LEAVE_DECIDED => __('My leave request was decided'),
            self::EMPLOYEE_REQUEST_SUBMITTED => __('A loan, petty cash or certificate request to decide'),
            self::EMPLOYEE_REQUEST_DECIDED => __('My loan, petty cash or certificate request was decided'),
            self::EMPLOYEE_REQUEST_TO_PAY => __('An approved loan or petty cash to pay'),
            self::CHAT_MESSAGE => __('A chat message'),
            self::CHAT_REACTION => __('A reaction to my chat message'),
            self::TODO_MENTIONED => __('I was mentioned in a to-do'),
            self::TODO_COMPLETED => __('A shared to-do was completed'),
            self::CALENDAR_UNMATCHED => __('Calendar events naming a matter that is not in the system'),
            self::CALENDAR_REMINDER => __('An event of my matter tomorrow, and in an hour'),
            self::FEES_CHANGED => __('A fee or a collection was added, changed or deleted'),
        };
    }

    /** The group it is listed under. */
    public function area(): string
    {
        return match ($this) {
            self::REQUEST_CREATED, self::REQUEST_DECIDED, self::REQUEST_CHANGES => __('Requests'),
            self::MATTER_ASSIGNED, self::MATTER_REPLY_RECEIVED, self::MATTER_EMAIL_RECEIVED, self::MATTER_EMAIL_UNFILED, self::MATTER_EMAIL_SENT, self::MATTER_EMAIL_FORWARDED,
            self::MINUTES_REPLY, self::ONEDRIVE_FOLDER_REVIEW => __('Matters'),
            self::LEAVE_SUBMITTED, self::LEAVE_DECIDED => __('Leave'),
            self::EMPLOYEE_REQUEST_SUBMITTED, self::EMPLOYEE_REQUEST_DECIDED, self::EMPLOYEE_REQUEST_TO_PAY => __('Employee requests'),
            self::CHAT_MESSAGE, self::CHAT_REACTION, self::TODO_MENTIONED, self::TODO_COMPLETED => __('Chat and to-dos'),
            self::CALENDAR_UNMATCHED, self::CALENDAR_REMINDER => __('Calendar'),
            self::FEES_CHANGED => __('Fees'),
        };
    }

    /**
     * The channels it can reach someone by.
     *
     * @return list<NotificationChannel>
     */
    public function channels(): array
    {
        $channels = match ($this) {
            // Live things: in the app (and on the phone with it).
            self::CHAT_MESSAGE, self::CHAT_REACTION, self::TODO_MENTIONED, self::TODO_COMPLETED => [NotificationChannel::BELL],
            // A daily report.
            self::CALENDAR_UNMATCHED => [NotificationChannel::MAIL],
            default => [NotificationChannel::BELL, NotificationChannel::MAIL, NotificationChannel::WHATSAPP],
        };

        // Only what really sends: no WhatsApp switch while nothing would go out on WhatsApp.
        return array_values(array_filter($channels, fn (NotificationChannel $channel): bool => $this->reaches($channel)));
    }

    /**
     * Whether something goes out on the channel for it: a new request on WhatsApp has its own approved
     * template (WhatsAppService::notifyNewRequest), so WhatsApp being set up is enough; the rest go out
     * with the notification template (NotificationChannel::isAvailable()).
     */
    private function reaches(NotificationChannel $channel): bool
    {
        return $channel === NotificationChannel::WHATSAPP && $this === self::REQUEST_CREATED
            ? WhatsAppCloud::configured()
            : $channel->isAvailable();
    }

    /**
     * The channels on until someone chooses — what the system does today.
     *
     * @return list<NotificationChannel>
     */
    public function defaults(): array
    {
        $bell = in_array(NotificationChannel::BELL, $this->channels(), true) ? [NotificationChannel::BELL] : [];

        return match ($this) {
            self::REQUEST_CREATED, self::REQUEST_DECIDED, self::REQUEST_CHANGES, self::MATTER_ASSIGNED, self::LEAVE_SUBMITTED, self::LEAVE_DECIDED,
            self::EMPLOYEE_REQUEST_SUBMITTED, self::EMPLOYEE_REQUEST_DECIDED, self::EMPLOYEE_REQUEST_TO_PAY => [...$bell, NotificationChannel::MAIL],
            self::CALENDAR_UNMATCHED => [NotificationChannel::MAIL],
            // Only for the roles given it (super admins and accountants to begin with).
            self::FEES_CHANGED => [],
            default => $bell,
        };
    }

    public function isOnByDefault(NotificationChannel $channel): bool
    {
        return in_array($channel, $this->defaults(), true);
    }

    /**
     * Those about emails — the bell's Emails tab (App\Livewire\TabbedDatabaseNotifications).
     *
     * @return list<self>
     */
    public static function aboutEmails(): array
    {
        return [self::MATTER_REPLY_RECEIVED, self::MATTER_EMAIL_RECEIVED, self::MATTER_EMAIL_UNFILED, self::MATTER_EMAIL_SENT, self::MATTER_EMAIL_FORWARDED];
    }

    public function supports(NotificationChannel $channel): bool
    {
        return in_array($channel, $this->channels(), true);
    }

    /**
     * Whether it is something this user can be told of at all: those about deciding (leave, requests,
     * the OneDrive folders) only for whoever may decide. The rest are about the user's own work.
     */
    public function isRelevantTo(User $user): bool
    {
        $permissions = match ($this) {
            self::LEAVE_SUBMITTED => ['Approve:LeaveRequest'],
            self::EMPLOYEE_REQUEST_SUBMITTED => ['Approve:EmployeeRequest'],
            self::EMPLOYEE_REQUEST_TO_PAY => ['Pay:EmployeeRequest'],
            self::REQUEST_CREATED => ['ApproveRequest:Matter', 'RejectRequest:Matter', 'Update:MatterRequest'],
            self::ONEDRIVE_FOLDER_REVIEW => ['View:OneDriveSettings'],
            self::MATTER_EMAIL_UNFILED => ['View:UnfiledEmails'],
            self::CALENDAR_UNMATCHED => ['View:UnmatchedEventReferences'],
            self::FEES_CHANGED => ['View:MatterFeesTab'],
            default => [],
        };

        return $permissions === [] || collect($permissions)->contains(fn (string $permission): bool => $user->can($permission));
    }

    /** The notification key kept in a bell notification's data. */
    public const DATA_KEY = 'notification_type';
}
