<?php

namespace Tests\Feature;

use App\Filament\Mms\Pages\AdminDashboard;
use App\Filament\Mms\Widgets\UnmatchedEventReferencesWidget;
use App\Mail\UnmatchedEventReferencesMail;
use App\Models\CalendarEvent;
use App\Models\Matter;
use App\Models\User;
use App\Services\MMS\Calendar\UnmatchedEventReferences;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Calendar events naming a matter number that no matter in the system has:
 * a dashboard widget, an hourly pop-up and a daily email for admins.
 */
class UnmatchedEventReferencesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en');
        Filament::setCurrentPanel('mms');
        Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    }

    private function event(string $title, string $when = '+2 days'): CalendarEvent
    {
        return CalendarEvent::create([
            'title' => $title,
            'start_datetime' => now()->modify($when),
            'end_datetime' => now()->modify($when)->addHour(),
            'type' => 'single',
        ]);
    }

    private function user(?string $role = null, string $email = 'someone@firm.ae'): User
    {
        $user = User::factory()->create(['email' => $email]);

        if ($role) {
            $user->assignRole($role);
        }

        return $user;
    }

    public function test_it_finds_numbers_no_matter_has(): void
    {
        Matter::factory()->create(['number' => 639, 'year' => 2025]);

        $found = $this->event('Session 639/2025');
        $missing = $this->event('12/2024, 639/2025 (Dubai Courts)');
        $typo = $this->event('Session 6399/2025');
        $noNumber = $this->event('Office meeting');
        $old = $this->event('Session 1/2020', '-60 days');

        $this->assertSame([
            $missing->id => ['12/2024'],
            $typo->id => ['6399/2025'],
        ], UnmatchedEventReferences::missing());

        // Adding the matter clears it straight away.
        Matter::factory()->create(['number' => 12, 'year' => 2024]);
        $this->assertSame([$typo->id], array_keys(UnmatchedEventReferences::missing()));
    }

    public function test_an_event_linked_by_hand_to_a_matter_is_no_longer_listed(): void
    {
        $matter = Matter::factory()->create(['number' => 639, 'year' => 2025]);
        $typo = $this->event('Session 6399/2025');

        $this->assertArrayHasKey($typo->id, UnmatchedEventReferences::missing());

        // Linking clears the cached list straight away.
        $typo->matters()->attach($matter->id);
        $this->assertSame([], UnmatchedEventReferences::missing());

        // …and unlinking brings it back.
        $typo->matters()->detach($matter->id);
        $this->assertArrayHasKey($typo->id, UnmatchedEventReferences::missing());
    }

    public function test_an_event_naming_two_numbers_needs_both_accounted_for(): void
    {
        $found = Matter::factory()->create(['number' => 12, 'year' => 2024]);
        $other = Matter::factory()->create(['number' => 77, 'year' => 2024]);
        $event = $this->event('12/2024, 15/2024 (Dubai Courts)');

        // 12/2024 is linked from the title; 15/2024 is still missing.
        $event->matters()->syncWithoutDetaching([$found->id]);
        $this->assertSame([$event->id => ['15/2024']], UnmatchedEventReferences::missing());

        $event->matters()->syncWithoutDetaching([$other->id]);
        $this->assertSame([], UnmatchedEventReferences::missing());
    }

    public function test_the_dashboard_widget_lists_them_only_when_there_are_some(): void
    {
        Gate::before(fn () => true);
        $this->actingAs($this->user('super-admin'));

        $this->assertFalse(UnmatchedEventReferencesWidget::canView());

        $event = $this->event('Session 6399/2025');

        $this->assertTrue(UnmatchedEventReferencesWidget::canView());
        Livewire::test(UnmatchedEventReferencesWidget::class)
            ->assertCanSeeTableRecords([$event])
            ->assertSee('6399/2025');
    }

    public function test_the_widget_disappears_once_nothing_is_missing(): void
    {
        Gate::before(fn () => true);
        $this->actingAs($this->user('super-admin'));
        $event = $this->event('Session 3153-2026');

        $widget = Livewire::test(UnmatchedEventReferencesWidget::class)->assertSee('3153/2026');

        // Fixed from elsewhere while the dashboard is open: no empty table left.
        Matter::factory()->create(['number' => 3153, 'year' => 2026]);
        $widget->call('$refresh')->assertDontSee(__('Events with a matter number not in the system'));

        // An event gone without model events (cache still lists it): hidden too.
        Matter::query()->delete();
        UnmatchedEventReferences::missing();
        CalendarEvent::query()->whereKey($event->id)->delete();
        $this->assertFalse(UnmatchedEventReferencesWidget::canView());
    }

    public function test_the_popup_closes_only_with_its_button(): void
    {
        Gate::before(fn () => true);
        $this->event('Session 6399/2025');
        $this->actingAs($this->user('admin'));

        $html = $this->get(AdminDashboard::getUrl(panel: 'mms'))->assertSuccessful()->getContent();
        $modal = substr($html, strpos($html, 'id="wakeel-unmatched-events"') ?: 0);

        $this->assertStringNotContainsString('x-on:keydown.escape', substr($modal, 0, 3000));
        $this->assertStringContainsString("close-modal', { id: 'wakeel-unmatched-events' }", $html);
    }

    public function test_admins_get_the_popup_on_any_page_others_do_not(): void
    {
        Gate::before(fn () => true);
        $this->event('Session 6399/2025');

        $this->actingAs($this->user('admin'));
        $this->get(AdminDashboard::getUrl(panel: 'mms'))
            ->assertSuccessful()
            ->assertSee('wakeel-unmatched-events', false)
            ->assertSee('6399/2025');

        $this->actingAs($this->user(email: 'staff@firm.ae'));
        $this->get(AdminDashboard::getUrl(panel: 'mms'))
            ->assertSuccessful()
            ->assertDontSee('wakeel-unmatched-events', false);
    }

    public function test_no_popup_when_every_number_matches(): void
    {
        Gate::before(fn () => true);
        Matter::factory()->create(['number' => 639, 'year' => 2025]);
        $this->event('Session 639/2025');

        $this->actingAs($this->user('super-admin'));
        $this->get(AdminDashboard::getUrl(panel: 'mms'))->assertDontSee('wakeel-unmatched-events', false);
    }

    public function test_super_admins_and_admins_get_the_daily_email(): void
    {
        Mail::fake();
        $this->user('super-admin', 'boss@firm.ae');
        $this->user('admin', 'office@firm.ae');
        $this->user(email: 'staff@firm.ae');
        $this->event('Session 6399/2025');

        $this->artisan('calendar:report-unmatched')->assertSuccessful();

        Mail::assertSent(UnmatchedEventReferencesMail::class, 2);
        Mail::assertSent(UnmatchedEventReferencesMail::class, fn ($mail) => $mail->hasTo('boss@firm.ae') && $mail->rows[0]['missing'] === ['6399/2025']);
        Mail::assertSent(UnmatchedEventReferencesMail::class, fn ($mail) => $mail->hasTo('office@firm.ae'));
        Mail::assertNotSent(UnmatchedEventReferencesMail::class, fn ($mail) => $mail->hasTo('staff@firm.ae'));
    }

    public function test_no_email_when_there_is_nothing_to_report(): void
    {
        Mail::fake();
        $this->user('admin', 'office@firm.ae');

        $this->artisan('calendar:report-unmatched')->assertSuccessful();

        Mail::assertNothingSent();
    }

    public function test_the_email_renders(): void
    {
        $html = (new UnmatchedEventReferencesMail([
            ['date' => 'Tue 29/09/2026', 'title' => 'Session 6399/2025', 'missing' => ['6399/2025']],
        ], 'https://wakeel.test/mms'))->render();

        $this->assertStringContainsString('6399/2025', $html);
        $this->assertStringContainsString('https://wakeel.test/mms', $html);
    }
}
