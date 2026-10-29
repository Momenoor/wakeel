<?php

namespace Tests\Feature;

use App\Filament\Mms\Pages\MailboxFolderEmails;
use App\Filament\Mms\Pages\MailboxFolders;
use App\Filament\Mms\Resources\MailSenders\Pages\ManageMailSenders;
use App\Models\MailboxFolderForward;
use App\Models\MailboxForwardedEmail;
use App\Models\MailSender;
use App\Models\User;
use App\Services\MMS\MailboxFolderForwarder;
use App\Services\MMS\SenderMailer;
use App\Services\MMS\SentFolder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Mockery;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Symfony\Component\Mime\Email;
use Tests\Support\MailSenders;
use Tests\TestCase;

/**
 * Mailbox folders: mailboxes of the super administrator's alone, each folder of them forwarded on to an address of
 * its own — what Yahoo's filters, which can only move an email, cannot do.
 */
class MailboxFolderForwardingTest extends TestCase
{
    use RefreshDatabase;

    private MailSender $yahoo;

    /** @var list<Email> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create()->assignRole(
            Role::firstOrCreate(['name' => config('filament-shield.super_admin.name', 'super_admin'), 'guard_name' => 'web'])
        ));
        Filament::setCurrentPanel('admin');

        $this->yahoo = MailSenders::add('office', ['address' => 'office@yahoo.com', 'host' => 'smtp.mail.yahoo.com', 'port' => 465, 'encryption' => 'ssl']);
        $this->yahoo->update(['folders_only' => true, 'sends' => false, 'collects' => false]);

        config(['mail.mailers.smtp.transport' => 'array']);
        Event::listen(MessageSent::class, fn (MessageSent $event) => $this->sent[] = $event->message);
    }

    /**
     * @return array<string, mixed>
     */
    private static function email(int $uid, string $subject, string $from = 'court@courts.ae'): array
    {
        return [
            'uid' => $uid,
            'message_id' => 'm'.$uid.'@courts.ae',
            'subject' => $subject,
            'from' => $from,
            'from_name' => 'Dubai Courts',
            'to' => ['office@yahoo.com'],
            'cc' => [],
            'at' => '2026-10-28T09:30:00+04:00',
            'seen' => false,
            'html' => '<p>Hearing on 5/11</p>',
            'raw' => "Subject: {$subject}\r\nFrom: {$from}\r\n\r\nHearing on 5/11",
        ];
    }

    private function folder(): SentFolder
    {
        $folder = Mockery::mock(SentFolder::class);
        $this->app->instance(SentFolder::class, $folder);

        return $folder;
    }

    public function test_a_folder_is_found_by_its_path_or_name_whatever_the_separator(): void
    {
        $folders = [
            (object) ['full_name' => 'INBOX', 'name' => 'INBOX', 'no_select' => false],
            (object) ['full_name' => 'INBOX.Wakeel', 'name' => 'Wakeel', 'no_select' => false],
            (object) ['full_name' => 'Courts/Dubai', 'name' => 'Dubai', 'no_select' => false],
            (object) ['full_name' => 'Archive', 'name' => 'Archive', 'no_select' => true],
        ];

        $this->assertSame('INBOX.Wakeel', SentFolder::named($folders, 'inbox/wakeel')->full_name);
        $this->assertSame('INBOX.Wakeel', SentFolder::named($folders, 'Wakeel')->full_name);
        $this->assertSame('Courts/Dubai', SentFolder::named($folders, 'Courts.Dubai')->full_name);
        $this->assertNull(SentFolder::named($folders, 'Archive'));
        $this->assertNull(SentFolder::named($folders, 'Nowhere'));

        // Yahoo's Inbox is "Inbox": found as INBOX all the same.
        $yahoo = [(object) ['full_name' => 'Inbox', 'name' => 'Inbox', 'no_select' => false], (object) ['full_name' => 'Wakeel', 'name' => 'Wakeel', 'no_select' => false]];
        $this->assertSame('Inbox', SentFolder::named($yahoo, 'INBOX')->full_name);
    }

    public function test_its_mailboxes_are_not_among_the_mail_senders_nor_sent_from_nor_shared(): void
    {
        $shared = MailSenders::add('noreply');

        $this->assertArrayHasKey('noreply', SenderMailer::options(sharedOnly: true));
        $this->assertArrayNotHasKey('office', SenderMailer::options(sharedOnly: true));
        $this->assertSame(['noreply'], array_keys(SenderMailer::sharedOptions()));

        Livewire::test(ManageMailSenders::class)
            ->assertCanSeeTableRecords([$shared])
            ->assertCanNotSeeTableRecords([$this->yahoo]);

        // Read for the matters' emails only while the module is on.
        $this->yahoo->update(['collects' => true, 'inbox_folder' => 'Wakeel']);
        SenderMailer::forget();
        $this->assertArrayHasKey('office', SenderMailer::collected());
        $this->assertSame('Wakeel', SenderMailer::sender('office')['folder']);

        config(['modules.'.MailboxFolderForwarder::MODULE => false]);
        SenderMailer::forget();
        $this->assertArrayNotHasKey('office', SenderMailer::collected());
        $this->assertSame('', SenderMailer::sender('office')['folder']);
    }

    public function test_the_super_administrator_alone_opens_it(): void
    {
        $this->assertTrue(MailboxFolders::canAccess());
        $this->assertTrue(MailboxFolderEmails::canAccess());

        $this->actingAs(User::factory()->create());
        $this->assertFalse(MailboxFolders::canAccess());
        $this->assertFalse(MailboxFolderEmails::canAccess());
    }

    public function test_a_mailbox_added_is_its_own_and_a_shared_one_is_taken_out_of_the_mail_senders(): void
    {
        $shared = MailSenders::add('gmail_box', ['address' => 'jpa.office@gmail.com']);

        Livewire::test(MailboxFolders::class)
            ->assertCanSeeTableRecords([$this->yahoo])
            ->callTableAction('add', data: ['address' => 'courts.box@yahoo.com', 'name' => 'Courts', 'host' => 'smtp.mail.yahoo.com', 'port' => 465, 'encryption' => 'ssl', 'username' => 'courts.box@yahoo.com', 'password' => 'app-password', 'collects' => false])
            ->assertHasNoTableActionErrors()
            ->callTableAction('add', data: ['address' => 'jpa.office@gmail.com', 'name' => 'Gmail', 'host' => 'smtp.gmail.com', 'port' => 465, 'encryption' => 'ssl', 'password' => 'app-password', 'collects' => false])
            ->assertHasNoTableActionErrors();

        $added = MailSender::query()->firstWhere('address', 'courts.box@yahoo.com');
        $this->assertSame([true, false, MailSender::SMTP, 'courtsbox'], [$added->folders_only, $added->sends, $added->driver, $added->key]);
        $this->assertSame('app-password', $added->password);
        $this->assertTrue($shared->fresh()->folders_only);
        $this->assertSame(1, MailSender::query()->where('address', 'jpa.office@gmail.com')->count());
    }

    public function test_a_folders_new_emails_are_forwarded_once_with_their_original(): void
    {
        $folder = $this->folder();
        $folder->shouldReceive('emails')->with('office', 'Wakeel', 7, MailboxFolderForwarder::PER_RUN)->once()
            ->andReturn([self::email(8, 'Hearing notice'), self::email(9, 'Judgment')]);
        $folder->shouldReceive('emails')->with('office', 'Wakeel', 9, MailboxFolderForwarder::PER_RUN)->once()->andReturn([]);
        MailboxFolderForward::query()->create(['mail_sender_id' => $this->yahoo->id, 'folder' => 'Wakeel', 'forward_to' => 'files@jpa.ae', 'last_uid' => 7]);

        $this->assertSame(2, app(MailboxFolderForwarder::class)->run());
        $this->assertSame(0, app(MailboxFolderForwarder::class)->run());

        $this->assertCount(2, $this->sent);
        $first = $this->sent[0];
        $this->assertSame('files@jpa.ae', $first->getTo()[0]->getAddress());
        $this->assertSame('office@yahoo.com', $first->getFrom()[0]->getAddress());
        $this->assertSame('Fwd: Hearing notice', $first->getSubject());
        $this->assertSame('court@courts.ae', $first->getReplyTo()[0]->getAddress());
        $this->assertStringContainsString('From: Dubai Courts &lt;court@courts.ae&gt;', $first->getHtmlBody());
        $this->assertStringContainsString('<p>Hearing on 5/11</p>', $first->getHtmlBody());
        $attachment = $first->getAttachments()[0];
        $this->assertSame('message/rfc822', $attachment->getMediaType().'/'.$attachment->getMediaSubtype());
        $this->assertStringContainsString('Subject: Hearing notice', $attachment->getBody());

        $this->assertSame(9, MailboxFolderForward::query()->first()->last_uid);
        $this->assertSame(2, MailboxForwardedEmail::query()->whereNotNull('forwarded_at')->count());
    }

    public function test_an_email_that_will_not_go_holds_the_folder_until_tried_three_times(): void
    {
        $folder = $this->folder();
        $folder->shouldReceive('emails')->andReturn([self::email(8, 'Hearing notice')]);
        $forward = MailboxFolderForward::query()->create(['mail_sender_id' => $this->yahoo->id, 'folder' => 'Wakeel', 'forward_to' => 'files@jpa.ae', 'last_uid' => 7]);
        $forwarder = Mockery::mock(MailboxFolderForwarder::class, [$folder])->makePartial();
        $forwarder->shouldReceive('send')->andThrow(new RuntimeException('535 Authentication failed'));

        $forwarder->forwardNew($forward);
        $forwarder->forwardNew($forward->fresh());
        $this->assertSame(7, $forward->fresh()->last_uid);
        $this->assertSame('535 Authentication failed', $forward->fresh()->error);

        // The third time: passed over, the folder goes on.
        $forwarder->forwardNew($forward->fresh());
        $this->assertSame(8, $forward->fresh()->last_uid);
        $this->assertNull($forward->fresh()->error);
        $this->assertSame(3, MailboxForwardedEmail::query()->first()->attempts);
    }

    public function test_any_folder_is_chosen_shown_and_forwarded_from_its_page(): void
    {
        $folder = $this->folder();
        $folder->shouldReceive('folders')->with('office')->andReturn(['INBOX', 'Wakeel', 'Bulk']);
        $folder->shouldReceive('emails')->with('office', 'Wakeel', 0, MailboxFolderEmails::SHOWN, true, false)->andReturn([self::email(9, 'Judgment'), self::email(8, 'Hearing notice')]);
        $folder->shouldReceive('emails')->with('office', 'Wakeel', 0, 1, true, false)->andReturn([self::email(9, 'Judgment')]);
        $folder->shouldReceive('emails')->with('office', 'Wakeel', 7, 1)->andReturn([self::email(8, 'Hearing notice')]);

        Livewire::test(MailboxFolderEmails::class, ['mailbox' => 'office'])
            ->set('folder', 'Wakeel')
            ->assertSee(['Judgment', 'Hearing notice'])
            // From now on: its newest email the last gone on.
            ->callTableAction('forwarding', data: ['forward_to' => 'files@jpa.ae', 'existing' => false])
            ->assertHasNoTableActionErrors()
            ->callTableAction('forward', '8', ['to' => 'lawyer@jpa.ae'])
            ->assertHasNoTableActionErrors();

        $forward = MailboxFolderForward::query()->sole();
        $this->assertSame(['Wakeel', 'files@jpa.ae', 9], [$forward->folder, $forward->forward_to, $forward->last_uid]);
        $this->assertSame('lawyer@jpa.ae', $this->sent[0]->getTo()[0]->getAddress());
        $this->assertSame('Fwd: Hearing notice', $this->sent[0]->getSubject());

        // Another mailbox's page, or none: not found.
        Livewire::test(MailboxFolderEmails::class, ['mailbox' => 'noreply'])->assertNotFound();
    }
}
