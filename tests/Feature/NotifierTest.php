<?php

namespace Tests\Feature;

use App\Enums\NotificationChannel as Channel;
use App\Enums\NotificationType as Type;
use App\Mail\UserNotificationMail;
use App\Models\Party;
use App\Models\User;
use App\Models\WhatsAppTemplate;
use App\Services\Notifications\NotificationPreferences as Prefs;
use App\Services\Notifications\Notifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * One title, text and link, by the bell, email and WhatsApp — each only to those who want it so.
 */
class NotifierTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // The WhatsApp messages go after the answer (defer()): sent at once here, to be seen.
        $this->withoutDefer();
    }

    public function test_each_user_is_told_by_the_channels_they_want(): void
    {
        Mail::fake();
        config(['services.whatsapp.token' => 't', 'services.whatsapp.phone_id' => '99']);
        $sent = [];
        Http::fake(function (Request $request) use (&$sent) {
            $sent[] = $request->data();

            return Http::response(['messages' => [['id' => 'wamid.1']]]);
        });
        WhatsAppTemplate::create(['name' => 'Notification', 'purpose' => WhatsAppTemplate::NOTIFICATION, 'meta_name' => 'user_notification', 'language' => 'ar', 'header' => 'none', 'body' => '{{title}}', 'is_active' => true, 'is_default' => true,
            'parameters' => [['name' => 'title', 'value' => '{{title}}'], ['name' => 'body', 'value' => '{{body}}'], ['name' => 'link', 'value' => '{{link}}']]]);

        // All three (WhatsApp turned on), and one who muted the bell and the email.
        $all = User::factory()->create(['display_name' => 'All']);
        Party::factory()->create(['user_id' => $all->id, 'phone' => ['0501234567']]);
        Prefs::make()->saveUser($all, [Type::MATTER_ASSIGNED->value => ['whatsapp' => true]]);
        $quiet = User::factory()->create(['display_name' => 'Quiet']);
        Prefs::make()->saveUser($quiet, [Type::MATTER_ASSIGNED->value => ['bell' => false, 'mail' => false]]);

        Notifier::send(Type::MATTER_ASSIGNED, [$all->refresh(), $quiet->refresh()], 'A matter was assigned', "Line one\nline two", 'https://wakeel.test/matters/1');

        // Bell: only for the one who wants it — with its type kept in it.
        $this->assertCount(1, $all->notifications);
        $this->assertCount(0, $quiet->notifications);
        $this->assertSame(Type::MATTER_ASSIGNED->value, $all->notifications->first()->data['viewData'][Type::DATA_KEY] ?? null);
        // Email: the same.
        Mail::assertQueued(UserNotificationMail::class, 1);
        Mail::assertQueued(UserNotificationMail::class, fn (UserNotificationMail $mail) => $mail->hasTo($all->email) && $mail->title === 'A matter was assigned');
        // WhatsApp: the one who turned it on, on one line.
        $this->assertCount(1, $sent);
        $this->assertSame('971501234567', $sent[0]['to']);
        $this->assertSame('Line one line two', $sent[0]['template']['components'][0]['parameters'][1]['text']);
    }

    public function test_only_the_chosen_channels_are_used_when_asked(): void
    {
        Mail::fake();
        $user = User::factory()->create();

        Notifier::send(Type::REQUEST_DECIDED, [$user], 'Approved', only: [Channel::BELL]);

        $this->assertCount(1, $user->notifications);
        Mail::assertNothingQueued();
    }
}
