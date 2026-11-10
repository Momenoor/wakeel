<?php

namespace Tests\Feature\Letters;

use App\Enums\LetterSendChannel;
use App\Enums\NotificationType;
use App\Filament\Mms\Resources\Matters\Pages\ViewMatter;
use App\Filament\Mms\Resources\Matters\RelationManagers\LettersRelationManager;
use App\Filament\Mms\Widgets\LettersToSendWidget;
use App\Models\Letterhead;
use App\Models\LetterSendRequest;
use App\Models\LetterTemplate;
use App\Models\Matter;
use App\Models\MatterLetter;
use App\Models\Party;
use App\Models\Setting;
use App\Models\User;
use App\Models\WhatsAppTemplate;
use App\Services\MMS\Letters\LetterIssuer;
use App\Services\MMS\Letters\LetterMailer;
use App\Services\MMS\Letters\LetterSendRequests;
use App\Services\MMS\SentFolder;
use App\Support\CompanyContact;
use App\Support\RequestMemo;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Mockery;
use Spatie\Permission\Models\Role;
use Tests\Support\MailSenders;
use Tests\TestCase;

/**
 * A letter an assistant issues and does not send is their administrator's to send: asked with the ways it
 * should go, the administrator and the office's WhatsApp number told, each way ticked once done, and the
 * dashboard listing those still to send.
 */
class LetterSendRequestsTest extends TestCase
{
    use RefreshDatabase;

    private User $assistant;

    private User $admin;

    private Matter $matter;

    private LetterTemplate $template;

    /** @var list<array<string, mixed>> what went to WhatsApp */
    private array $whatsapp = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->withoutDefer();
        Filament::setCurrentPanel('admin');

        // The assistant may work on the matter's page (a super admin's rights here); what makes their letters
        // their administrator's to send is being an assistant.
        $this->assistant = User::factory()->create(['name' => 'Sara', 'display_name' => 'Sara'])->assignRole(
            Role::firstOrCreate(['name' => config('filament-shield.super_admin.name', 'super_admin'), 'guard_name' => 'web'])
        );
        $this->admin = User::factory()->create(['name' => 'Huda', 'display_name' => 'Huda']);
        Party::factory()->assistant()->create(['user_id' => $this->assistant->id])->administrators()->attach($this->admin);

        $this->matter = Matter::factory()->create(['number' => '986', 'year' => '2026']);
        $this->template = LetterTemplate::create([
            'name' => 'إشعار', 'slug' => 'notice', 'locale' => 'ar', 'category' => 'letter',
            'letterhead_id' => Letterhead::create(['name' => 'Main', 'is_default' => true, 'elements' => Letterhead::defaultElements()])->id,
            'subject' => 'إشعار الدعوى رقم {{matter.number}}',
            'body' => '<p>{{recipients}}</p><p>نص الخطاب.</p>',
            'sms_template' => 'خطاب {{reference}} في الدعوى {{matter.number}}/{{matter.year}}: {{subject}}',
        ]);

        config(['services.whatsapp.token' => 't', 'services.whatsapp.phone_id' => '99']);
        Http::fake(function (Request $request) {
            $this->whatsapp[] = $request->data();

            return Http::response(['messages' => [['id' => 'wamid.1']]]);
        });
        Setting::set(CompanyContact::WHATSAPP, '0501234567');
        Setting::clearCache();
        WhatsAppTemplate::create(['name' => 'Letter to send', 'purpose' => WhatsAppTemplate::LETTER_TO_SEND, 'meta_name' => 'letter_to_send', 'language' => 'ar', 'header' => 'none',
            'body' => '{{matter}} {{ways}}', 'is_active' => true, 'is_default' => true,
            'parameters' => [['name' => 'matter', 'value' => '{{matter}}'], ['name' => 'letter', 'value' => '{{letter}}'], ['name' => 'ways', 'value' => '{{ways}}'], ['name' => 'assistant', 'value' => '{{assistant}}']]]);

        $this->actingAs($this->assistant);
    }

    private function letter(): MatterLetter
    {
        return app(LetterIssuer::class)->issue($this->template, $this->matter, [
            ['name' => 'منى أحمد', 'role' => 'المدعي', 'emails' => ['mona@example.com'], 'phones' => ['0507654321']],
        ], [], userId: $this->assistant->id);
    }

    private function bells(User $user, NotificationType $type): int
    {
        return $user->notifications()->get()->filter(fn ($notification): bool => ($notification->data['viewData'][NotificationType::DATA_KEY] ?? null) === $type->value)->count();
    }

    public function test_an_assistants_letter_is_asked_of_their_administrator_as_it_is_issued(): void
    {
        Livewire::test(LettersRelationManager::class, ['ownerRecord' => $this->matter, 'pageClass' => ViewMatter::class])
            ->mountTableAction('issue')
            // Email to begin with: something is always asked.
            ->assertTableActionDataSet(['send_channels' => ['email']])
            ->setTableActionData([
                'letter_template_id' => $this->template->id,
                'extra_recipients' => [['name' => 'Court clerk', 'role' => null, 'emails' => ['clerk@court.ae']]],
                'send_channels' => ['sms', 'whatsapp'],
                'send_note' => 'Before Thursday',
            ])
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();

        $request = LetterSendRequest::sole();
        $this->assertSame(['whatsapp', 'sms'], $request->channels);
        $this->assertSame([$this->assistant->id, 'Before Thursday', true], [$request->requested_by, $request->note, $request->isOpen()]);
        // The template's SMS, filled from the letter.
        $this->assertSame('خطاب JPA/2026/986/1 في الدعوى 986/2026: إشعار الدعوى رقم 986', $request->sms_text);

        // The administrator told in the bell — not the assistant.
        $this->assertSame([1, 0], [$this->bells($this->admin, NotificationType::LETTER_TO_SEND), $this->bells($this->assistant, NotificationType::LETTER_TO_SEND)]);
        // The office's WhatsApp number, by the approved template.
        $this->assertCount(1, $this->whatsapp);
        $this->assertSame(['971501234567', 'letter_to_send'], [$this->whatsapp[0]['to'], $this->whatsapp[0]['template']['name']]);
        $this->assertSame(
            ['matter' => '986/2026', 'letter' => 'JPA/2026/986/1', 'ways' => 'WhatsApp، SMS', 'assistant' => 'Sara'],
            array_column($this->whatsapp[0]['template']['components'][0]['parameters'], 'text', 'parameter_name'),
        );
    }

    public function test_a_letter_issued_by_someone_who_is_no_assistant_asks_nothing(): void
    {
        $this->actingAs(User::factory()->create()->assignRole(config('filament-shield.super_admin.name', 'super_admin')));

        Livewire::test(LettersRelationManager::class, ['ownerRecord' => $this->matter, 'pageClass' => ViewMatter::class])
            ->mountTableAction('issue')
            ->setTableActionData(['letter_template_id' => $this->template->id, 'extra_recipients' => [['name' => 'Court clerk', 'role' => null, 'emails' => ['clerk@court.ae']]]])
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();

        $this->assertSame([1, 0], [MatterLetter::count(), LetterSendRequest::count()]);
        $this->assertSame([], $this->whatsapp);
    }

    public function test_each_way_is_ticked_once_done_and_whoever_asked_is_told_at_the_last(): void
    {
        $letter = $this->letter();
        $requests = app(LetterSendRequests::class);
        $request = $requests->ask($letter, ['sms', 'email'], $this->assistant);

        // Asked again with the same ways: nobody told twice.
        $requests->ask($letter, ['email', 'sms'], $this->assistant, 'A note');
        $this->assertSame([1, 1], [$this->bells($this->admin, NotificationType::LETTER_TO_SEND), count($this->whatsapp)]);

        // The administrator works from the letter's row: the SMS to copy, each way ticked.
        $this->actingAs($this->admin->assignRole(config('filament-shield.super_admin.name', 'super_admin')));
        RequestMemo::flush();
        Livewire::test(LettersRelationManager::class, ['ownerRecord' => $this->matter, 'pageClass' => ViewMatter::class])
            ->mountTableAction('toSend', $letter)
            // The window shows the SMS to copy and the recipients' numbers.
            ->tap(function ($component) use ($request): void {
                $html = html_entity_decode($component->instance()->getMountedTableActionForm()->toHtml());
                $this->assertStringContainsString($request->fresh()->sms_text, $html);
                $this->assertStringContainsString('0507654321', $html);
                $this->assertStringContainsString('Sara', $html);
            })
            // The ways asked for, to tick.
            ->assertFormFieldExists('sent', 'mountedActionSchema0', fn ($field): bool => array_keys($field->getOptions()) === ['email', 'sms'])
            ->setTableActionData(['sent' => ['sms']])
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();

        $request->refresh();
        $this->assertSame([['email'], ['sms'], true], [$request->pendingChannels(), $request->doneChannels(), $request->isOpen()]);
        $this->assertSame($this->admin->id, $request->done['sms']['by']);
        $this->assertSame(0, $this->bells($this->assistant, NotificationType::LETTER_SENT_FOR_ME));

        // The last way done: closed, and the assistant told.
        $requests->done($request, LetterSendChannel::EMAIL, $this->admin);
        $this->assertFalse($request->fresh()->isOpen());
        $this->assertSame(1, $this->bells($this->assistant, NotificationType::LETTER_SENT_FOR_ME));

        // Done is done: a way not asked for, or ticked again, changes nothing.
        $requests->done($request->fresh(), LetterSendChannel::WHATSAPP, $this->admin);
        $this->assertSame(['email', 'sms'], $request->fresh()->doneChannels());
    }

    public function test_sending_the_letter_by_email_from_the_system_ticks_email_by_itself(): void
    {
        MailSenders::add('iflas', [
            'username' => 'iflas@jpaemirates.com', 'address' => 'iflas@jpaemirates.com', 'name' => 'JPA Iflas',
            'password' => 'secret', 'host' => 'mail.example.com', 'port' => 587, 'encryption' => 'tls',
        ]);
        config(['mail.mailers.smtp.transport' => 'array']);
        $this->app->instance(SentFolder::class, Mockery::spy(SentFolder::class));

        $letter = $this->letter();
        app(LetterSendRequests::class)->ask($letter, ['email'], $this->assistant);

        $this->actingAs($this->admin);
        $result = app(LetterMailer::class)->send($letter, 'iflas', LetterMailer::BODY, null, []);

        $this->assertSame(1, $result['sent']);
        $this->assertFalse($letter->sendRequest()->first()->isOpen());
        $this->assertSame(1, $this->bells($this->assistant, NotificationType::LETTER_SENT_FOR_ME));
    }

    public function test_the_ways_are_changed_on_the_edit_screen_and_the_administrator_told_again(): void
    {
        $letter = $this->letter();
        app(LetterSendRequests::class)->ask($letter, ['email'], $this->assistant);

        Livewire::test(LettersRelationManager::class, ['ownerRecord' => $this->matter, 'pageClass' => ViewMatter::class])
            ->mountTableAction('editLetter', $letter)
            ->assertTableActionDataSet(['send_channels' => ['email']])
            ->setTableActionData(['send_channels' => ['email', 'sms'], 'send_sms_text' => 'Your letter is ready.'])
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();

        $request = LetterSendRequest::sole();
        $this->assertSame([['email', 'sms'], 'Your letter is ready.'], [$request->channels, $request->sms_text]);
        $this->assertSame(2, $this->bells($this->admin, NotificationType::LETTER_TO_SEND));
    }

    public function test_the_dashboard_lists_them_for_the_administrator_and_whoever_asked_only(): void
    {
        $request = app(LetterSendRequests::class)->ask($this->letter(), ['whatsapp'], $this->assistant);
        $stranger = User::factory()->create();

        $open = fn (User $user): array => LetterSendRequests::openFor($user)->pluck('id')->all();
        $this->assertSame([[$request->id], [$request->id], []], [$open($this->admin), $open($this->assistant), $open($stranger)]);
        $this->assertSame([true, false], [LetterSendRequests::mayHandle($this->admin, $request), LetterSendRequests::mayHandle($stranger, $request)]);

        $this->actingAs($this->admin);
        RequestMemo::flush();
        $this->assertTrue(LettersToSendWidget::canView());
        Livewire::test(LettersToSendWidget::class)
            ->assertCanSeeTableRecords([$request])
            ->assertSee('986/2026')
            ->callTableAction('toSend', $request, ['sent' => ['whatsapp']]);

        // Sent: off the list, and the widget with it.
        $this->assertFalse($request->fresh()->isOpen());
        RequestMemo::flush();
        $this->assertFalse(LettersToSendWidget::canView());
    }

    public function test_a_letter_with_no_template_sms_gets_a_line_naming_it(): void
    {
        $this->template->update(['sms_template' => null]);
        Setting::set('company_name', 'JPA');
        Setting::clearCache();

        $this->assertStringContainsString('خطاب رقم JPA/2026/986/1 — إشعار الدعوى رقم 986', LetterSendRequests::smsText($this->letter()));
    }
}
