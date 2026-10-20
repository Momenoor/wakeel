<?php

namespace Tests\Feature;

use App\Enums\NotificationType;
use App\Jobs\CollectMatterEmails;
use App\Livewire\TabbedDatabaseNotifications;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The bell's list in two tabs: All, and Emails — only what is about emails, with its own unread count; the
 * bell's count stays everything, and "Mark all as read" acts on the tab that is open.
 */
class NotificationTabsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('mms');
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    private function bell(string $title, ?NotificationType $type = null): void
    {
        $notification = Notification::make()->title($title);

        if ($type) {
            $notification->viewData([NotificationType::DATA_KEY => $type->value]);
        }

        $notification->sendToDatabase($this->user);
    }

    public function test_all_and_emails(): void
    {
        $this->bell('A reply came', NotificationType::MATTER_REPLY_RECEIVED);
        $this->bell('An email waits for its matter', NotificationType::MATTER_EMAIL_UNFILED);
        $this->bell('A leave request to decide', NotificationType::LEAVE_SUBMITTED);
        $this->bell('Something without a type');
        // The result of checking a matter for emails: about emails too.
        CollectMatterEmails::notification(['replies' => 1, 'emails' => 0, 'unfiled' => 0, 'errors' => [], 'stopped' => false, 'unlinked' => []])->sendToDatabase($this->user);

        $bell = Livewire::test(TabbedDatabaseNotifications::class)
            ->assertSee('A reply came')
            ->assertSee('A leave request to decide')
            ->assertSee('Something without a type')
            ->assertSee(__('Emails'));

        $this->assertSame(5, $bell->instance()->getUnreadNotificationsCount());
        $this->assertSame(3, $bell->instance()->getUnreadEmailsCount());

        $bell->call('showTab', 'emails')
            ->assertSee('A reply came')
            ->assertSee('An email waits for its matter')
            ->assertSee(trans_choice('No new replies.|:count new reply kept.|:count new replies kept.', 1, ['count' => 1]))
            ->assertDontSee('A leave request to decide')
            ->assertDontSee('Something without a type');

        // The bell's count is still everything.
        $this->assertSame(5, $bell->instance()->getUnreadNotificationsCount());

        // Mark all as read, in the Emails tab: those about emails only.
        $bell->call('markAllNotificationsAsRead');
        $this->assertSame(2, $this->user->unreadNotifications()->count());
        $this->assertSame(0, $bell->instance()->getUnreadEmailsCount());
    }

    public function test_an_empty_tab_keeps_the_tabs(): void
    {
        $this->bell('A leave request to decide', NotificationType::LEAVE_SUBMITTED);

        Livewire::test(TabbedDatabaseNotifications::class)
            ->call('showTab', 'emails')
            ->assertSee(__('No notifications about emails'))
            ->assertSee(__('All'))
            ->assertDontSee('A leave request to decide');
    }

    public function test_the_panels_bell_uses_it(): void
    {
        $this->assertSame(TabbedDatabaseNotifications::class, Filament::getPanel('mms')->getDatabaseNotificationsLivewireComponent());
    }
}
