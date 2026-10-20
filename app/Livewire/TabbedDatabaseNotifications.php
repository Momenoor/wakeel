<?php

namespace App\Livewire;

use App\Enums\NotificationType;
use Filament\Notifications\Livewire\DatabaseNotifications;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * The bell's list in two tabs: everything, and only what is about emails — replies to emails sent, emails kept
 * from a matter's party, emails waiting for their matter, the result of checking for emails. The bell's own
 * count stays every unread notification; the Emails tab shows its own. "Mark all as read" and "Clear" act on
 * the tab that is open.
 */
class TabbedDatabaseNotifications extends DatabaseNotifications
{
    public const ALL = 'all';

    public const EMAILS = 'emails';

    public string $tab = self::ALL;

    public function showTab(string $tab): void
    {
        $this->tab = $tab === self::EMAILS ? self::EMAILS : self::ALL;
        $this->resetPage('database-notifications-page');
    }

    public function getNotificationsQuery(): Builder|Relation
    {
        $query = parent::getNotificationsQuery();

        return $this->tab === self::EMAILS ? self::onlyEmails($query) : $query;
    }

    /** Every unread notification, whichever tab is open: the bell's own count. */
    public function getUnreadNotificationsCount(): int
    {
        return parent::getNotificationsQuery()->unread()->count();
    }

    public function getUnreadEmailsCount(): int
    {
        return self::onlyEmails(parent::getNotificationsQuery())->unread()->count();
    }

    /** The notifications about emails: their type, kept in the notification (NotificationType::DATA_KEY). */
    public static function onlyEmails(Builder|Relation $query): Builder|Relation
    {
        return $query->whereIn('data->viewData->'.NotificationType::DATA_KEY, array_map(
            fn (NotificationType $type): string => $type->value,
            NotificationType::aboutEmails(),
        ));
    }

    public function render(): View
    {
        return view('livewire.tabbed-database-notifications');
    }
}
