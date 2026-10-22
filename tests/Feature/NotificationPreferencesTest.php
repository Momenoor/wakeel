<?php

namespace Tests\Feature;

use App\Enums\NotificationChannel as Channel;
use App\Enums\NotificationType as Type;
use App\Filament\Mms\Pages\Auth\CustomProfile;
use App\Filament\Shared\Pages\NotificationSettings;
use App\Filament\Support\NotificationPreferenceFields as Fields;
use App\Models\NotificationPreference;
use App\Models\RoleNotificationDefault;
use App\Models\User;
use App\Models\WhatsAppTemplate;
use App\Services\Notifications\NotificationPreferences as Prefs;
use Filament\Facades\Filament;
use Filament\Support\Enums\Width;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Once;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Who is told of what, by which channel: the types' defaults, a role's states over them, and the
 * user's own choice over those.
 */
class NotificationPreferencesTest extends TestCase
{
    use RefreshDatabase;

    /** WhatsApp set up, with the template notifications go out with: offered as a channel. */
    protected function setUp(): void
    {
        parent::setUp();

        config(['services.whatsapp.token' => 't', 'services.whatsapp.phone_id' => '99']);
        WhatsAppTemplate::create(['name' => 'Notification', 'purpose' => WhatsAppTemplate::NOTIFICATION, 'meta_name' => 'user_notification', 'language' => 'ar',
            'header' => 'none', 'body' => '{{title}}', 'is_active' => true, 'is_default' => true, 'parameters' => [['name' => 'title', 'value' => '{{title}}']]]);
    }

    public function test_a_channel_that_sends_nothing_is_not_offered(): void
    {
        $user = $this->user();
        WhatsAppTemplate::query()->delete();
        Once::flush();

        // No notification template: WhatsApp offered only where it has its own (a new request's).
        $this->assertSame(Prefs::HIDDEN, $this->state($user, Type::REQUEST_DECIDED, Channel::WHATSAPP));
        $this->assertSame(Prefs::OFF, $this->state($user, Type::REQUEST_CREATED, Channel::WHATSAPP));

        // WhatsApp not set up at all: offered nowhere.
        config(['services.whatsapp.token' => null]);
        Once::flush();
        $this->assertSame(Prefs::HIDDEN, $this->state($user, Type::REQUEST_CREATED, Channel::WHATSAPP));
        $this->assertSame(Prefs::ON, $this->state($user, Type::REQUEST_DECIDED, Channel::BELL));
    }

    private function user(string ...$roles): User
    {
        $user = User::factory()->create(['display_name' => 'Test User']);

        foreach ($roles as $role) {
            $user->assignRole(Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']));
        }

        return $user->refresh();
    }

    private function role(string $name, array $cells): Role
    {
        $role = Role::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        Prefs::make()->saveRole($role, $cells);

        return $role;
    }

    private function state(User $user, Type $type, Channel $channel): string
    {
        return Prefs::make()->state($user, $type, $channel);
    }

    public function test_with_nothing_set_it_is_what_the_system_sends_today(): void
    {
        $user = $this->user();

        // Bell for everything; email where emails go out; WhatsApp for nothing; a channel a type cannot use is not offered.
        $this->assertSame(Prefs::ON, $this->state($user, Type::MATTER_REPLY_RECEIVED, Channel::BELL));
        $this->assertSame(Prefs::OFF, $this->state($user, Type::MATTER_REPLY_RECEIVED, Channel::MAIL));
        $this->assertSame(Prefs::ON, $this->state($user, Type::REQUEST_DECIDED, Channel::MAIL));
        $this->assertSame(Prefs::OFF, $this->state($user, Type::REQUEST_DECIDED, Channel::WHATSAPP));
        $this->assertSame(Prefs::HIDDEN, $this->state($user, Type::CHAT_MESSAGE, Channel::MAIL));
        $this->assertSame(Prefs::HIDDEN, $this->state($user, Type::CALENDAR_UNMATCHED, Channel::BELL));
    }

    public function test_a_users_own_choice_wins_over_the_default_and_is_kept_only_when_it_differs(): void
    {
        $user = $this->user();
        $prefs = Prefs::make();

        $prefs->saveUser($user, [Type::REQUEST_DECIDED->value => ['mail' => false, 'bell' => true, 'whatsapp' => true]]);

        $this->assertSame(Prefs::OFF, $this->state($user, Type::REQUEST_DECIDED, Channel::MAIL));
        $this->assertSame(Prefs::ON, $this->state($user, Type::REQUEST_DECIDED, Channel::WHATSAPP));
        // Bell was on already: nothing kept for it.
        $this->assertEqualsCanonicalizing(['mail', 'whatsapp'], NotificationPreference::pluck('channel')->all());

        // Back to the default: the row goes, so the user follows the role's defaults again.
        $prefs->saveUser($user->refresh(), [Type::REQUEST_DECIDED->value => ['mail' => true, 'whatsapp' => true, 'bell' => true]]);
        $this->assertSame(1, NotificationPreference::count());
    }

    public function test_a_role_makes_it_mandatory_or_not_offered_and_the_users_choice_cannot_change_that(): void
    {
        $this->role('assistant', [Type::REQUEST_DECIDED->value => ['mail' => Prefs::LOCKED, 'whatsapp' => Prefs::HIDDEN, 'bell' => Prefs::OFF]]);
        $user = $this->user('assistant');

        $this->assertSame(Prefs::LOCKED, $this->state($user, Type::REQUEST_DECIDED, Channel::MAIL));
        $this->assertSame(Prefs::HIDDEN, $this->state($user, Type::REQUEST_DECIDED, Channel::WHATSAPP));
        $this->assertSame(Prefs::OFF, $this->state($user, Type::REQUEST_DECIDED, Channel::BELL));

        // They try to turn the mandatory one off and the hidden one on: nothing is kept.
        Prefs::make()->saveUser($user, [Type::REQUEST_DECIDED->value => ['mail' => false, 'whatsapp' => true, 'bell' => true]]);
        $user = $user->refresh();
        $this->assertSame(Prefs::LOCKED, $this->state($user, Type::REQUEST_DECIDED, Channel::MAIL));
        $this->assertSame(Prefs::HIDDEN, $this->state($user, Type::REQUEST_DECIDED, Channel::WHATSAPP));
        // What is only off to begin with, they can turn on.
        $this->assertSame(Prefs::ON, $this->state($user, Type::REQUEST_DECIDED, Channel::BELL));
        $this->assertSame(['bell'], NotificationPreference::pluck('channel')->all());
    }

    public function test_with_several_roles_mandatory_from_any_wins_and_hidden_only_when_all_hide_it(): void
    {
        $this->role('a', [Type::MATTER_ASSIGNED->value => ['mail' => Prefs::HIDDEN, 'bell' => Prefs::OFF, 'whatsapp' => Prefs::LOCKED]]);
        $this->role('b', [Type::MATTER_ASSIGNED->value => ['mail' => Prefs::ON, 'bell' => Prefs::HIDDEN, 'whatsapp' => Prefs::OFF]]);
        $user = $this->user('a', 'b');

        $this->assertSame(Prefs::ON, $this->state($user, Type::MATTER_ASSIGNED, Channel::MAIL));      // one hides, one offers
        $this->assertSame(Prefs::OFF, $this->state($user, Type::MATTER_ASSIGNED, Channel::BELL));     // one hides, one has it off
        $this->assertSame(Prefs::LOCKED, $this->state($user, Type::MATTER_ASSIGNED, Channel::WHATSAPP));

        $this->role('c', [Type::MATTER_ASSIGNED->value => ['mail' => Prefs::HIDDEN]]);
        $this->role('d', [Type::MATTER_ASSIGNED->value => ['mail' => Prefs::HIDDEN]]);
        $this->assertSame(Prefs::HIDDEN, $this->state($this->user('c', 'd'), Type::MATTER_ASSIGNED, Channel::MAIL));
    }

    public function test_wanting_picks_the_users_to_tell_by_a_channel(): void
    {
        $wants = $this->user();
        $mutedMail = $this->user();
        Prefs::make()->saveUser($mutedMail, [Type::REQUEST_CREATED->value => ['mail' => false]]);

        $mail = Prefs::wanting(Type::REQUEST_CREATED, [$wants, $mutedMail->refresh()], Channel::MAIL);
        $bell = Prefs::wanting(Type::REQUEST_CREATED, [$wants, $mutedMail], Channel::BELL);

        $this->assertSame([$wants->id], $mail->pluck('id')->all());
        $this->assertCount(2, $bell);
        // A channel the type cannot use reaches nobody.
        $this->assertCount(0, Prefs::wanting(Type::CHAT_MESSAGE, [$wants], Channel::MAIL));
    }

    public function test_only_what_is_relevant_and_offered_is_shown_to_the_user(): void
    {
        Permission::firstOrCreate(['name' => 'Approve:LeaveRequest', 'guard_name' => 'web']);
        $employee = $this->user();
        $approver = $this->user();
        $approver->givePermissionTo('Approve:LeaveRequest');

        $this->assertNotContains(Type::LEAVE_SUBMITTED, Prefs::make()->visibleTypes($employee));
        $this->assertContains(Type::LEAVE_SUBMITTED, Prefs::make()->visibleTypes($approver->refresh()));
        $this->assertContains(Type::LEAVE_DECIDED, Prefs::make()->visibleTypes($employee));

        // A role that hides every channel of a type takes it off the page.
        $this->role('quiet', [Type::TODO_MENTIONED->value => ['bell' => Prefs::HIDDEN]]);
        $this->assertNotContains(Type::TODO_MENTIONED, Prefs::make()->visibleTypes($this->user('quiet')));
    }

    public function test_the_profile_shows_the_choices_and_saves_them(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('mms'));
        $this->role('assistant', [Type::REQUEST_DECIDED->value => ['bell' => Prefs::LOCKED]]);
        $user = $this->user('assistant');
        $this->actingAs($user);

        $key = fn (Type $type, Channel $channel) => Fields::STATE.'.'.str_replace('.', '__', $type->value).'.'.$channel->value;

        // The whole width of the page, in two columns — on the normal layout, where the pinned chat and to-do list make room.
        $this->assertFalse(CustomProfile::isSimple());
        $this->assertSame(Width::Full, (new CustomProfile)->getMaxContentWidth());

        Livewire::test(CustomProfile::class)
            ->assertFormSet([$key(Type::REQUEST_DECIDED, Channel::MAIL) => true, $key(Type::REQUEST_DECIDED, Channel::BELL) => true, $key(Type::REQUEST_DECIDED, Channel::WHATSAPP) => false])
            // Mandatory is shown on and cannot be changed; what the role does not offer is not on the page.
            ->assertFormFieldDisabled($key(Type::REQUEST_DECIDED, Channel::BELL))
            ->assertFormFieldExists($key(Type::MATTER_REPLY_RECEIVED, Channel::BELL))
            ->assertFormFieldDoesNotExist($key(Type::CHAT_MESSAGE, Channel::MAIL))
            ->fillForm([$key(Type::REQUEST_DECIDED, Channel::MAIL) => false, $key(Type::REQUEST_DECIDED, Channel::WHATSAPP) => true])
            ->call('save')
            ->assertHasNoFormErrors();

        $user = $user->refresh();
        $this->assertSame(Prefs::OFF, Prefs::make()->state($user, Type::REQUEST_DECIDED, Channel::MAIL));
        $this->assertSame(Prefs::ON, Prefs::make()->state($user, Type::REQUEST_DECIDED, Channel::WHATSAPP));
        $this->assertSame(Prefs::LOCKED, Prefs::make()->state($user, Type::REQUEST_DECIDED, Channel::BELL));
    }

    public function test_the_profile_areas_are_shared_between_two_columns_without_losing_any(): void
    {
        $user = $this->user();

        $all = Fields::forUser($user);
        [$left, $right] = Fields::forUserInColumns($user, leftStart: 8, rightStart: 3);

        // Every area once, in one column or the other — and both columns have some.
        $labels = fn (array $sections) => array_map(fn ($section) => $section->getHeading(), $sections);
        $this->assertEqualsCanonicalizing($labels($all), [...$labels($left), ...$labels($right)]);
        $this->assertNotEmpty($left);
        $this->assertNotEmpty($right);
    }

    public function test_the_settings_page_sets_a_roles_states(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('mms'));
        $role = Role::firstOrCreate(['name' => 'assistant', 'guard_name' => 'web']);
        foreach (['View:SystemSettings', 'View:SystemSettingsNotificationsTab'] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
        $admin = $this->user();
        $admin->givePermissionTo(['View:SystemSettings', 'View:SystemSettingsNotificationsTab']);
        $this->actingAs($admin);

        $key = fn (Type $type, Channel $channel) => Fields::STATE.'.'.str_replace('.', '__', $type->value).'.'.$channel->value;

        Livewire::test(NotificationSettings::class)
            ->fillForm(['role_id' => $role->id])
            ->assertFormSet([$key(Type::MATTER_ASSIGNED, Channel::MAIL) => Prefs::ON, $key(Type::MATTER_ASSIGNED, Channel::WHATSAPP) => Prefs::OFF])
            ->fillForm([$key(Type::MATTER_ASSIGNED, Channel::MAIL) => Prefs::LOCKED, $key(Type::MATTER_ASSIGNED, Channel::WHATSAPP) => Prefs::HIDDEN, $key(Type::MATTER_ASSIGNED, Channel::BELL) => Prefs::ON])
            ->call('save')
            ->assertHasNoFormErrors();

        // Only what differs from the defaults is kept.
        $this->assertEqualsCanonicalizing(['mail:locked', 'whatsapp:hidden'], RoleNotificationDefault::get()->map(fn ($row) => $row->channel.':'.$row->state)->all());
        $this->assertSame(Prefs::LOCKED, Prefs::make()->state($this->user('assistant'), Type::MATTER_ASSIGNED, Channel::MAIL));
    }
}
