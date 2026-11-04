<?php

namespace Tests\Feature;

use App\Filament\Mms\Resources\Matters\Pages\ViewMatter;
use App\Filament\Mms\Resources\Matters\RelationManagers\EmailsRelationManager;
use App\Models\Matter;
use App\Models\MatterContact;
use App\Models\MatterEmail;
use App\Models\MatterParty;
use App\Models\Party;
use App\Models\User;
use App\Services\MMS\EmailReplySender;
use App\Services\MMS\SentFolder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Mockery;
use Spatie\Permission\Models\Role;
use Symfony\Component\Mime\Email;
use Tests\Support\MailSenders;
use Tests\TestCase;

/**
 * A reply to an email that came in on a matter, written on its Emails tab.
 */
class EmailReplyTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<Email> */
    private array $sent = [];

    private Matter $matter;

    private Party $salem;

    private MatterEmail $email;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('local');
        $this->withoutDefer();

        MailSenders::add('iflas', [
            'username' => 'iflas@jpaemirates.com', 'address' => 'iflas@jpaemirates.com', 'name' => 'JPA Iflas',
            'password' => 'secret', 'host' => 'mail.example.com', 'port' => 587, 'encryption' => 'tls',
        ]);
        config(['mail.mailers.smtp.transport' => 'array']);
        Event::listen(MessageSent::class, fn (MessageSent $event) => $this->sent[] = $event->message);
        $this->app->instance(SentFolder::class, Mockery::spy(SentFolder::class));
        $this->actingAs(User::factory()->create()->assignRole(Role::firstOrCreate(['name' => config('filament-shield.super_admin.name', 'super_admin'), 'guard_name' => 'web'])));
        Filament::setCurrentPanel(Filament::getPanel('mms'));

        $this->matter = Matter::factory()->create(['number' => '321', 'year' => 2026]);
        // سالم, a party, whose own address is main@x.ae; on this matter he wrote from other@x.ae too, and brought in
        // his assistant's address.
        $this->salem = Party::factory()->create(['name' => 'سالم', 'email' => ['main@x.ae']]);
        MatterParty::create(['matter_id' => $this->matter->id, 'party_id' => $this->salem->id, 'role' => 'party', 'type' => 'plaintiff']);
        MatterContact::create(['matter_id' => $this->matter->id, 'party_id' => $this->salem->id, 'owner_email' => 'main@x.ae', 'owner_name' => 'سالم', 'email' => 'other@x.ae']);
        MatterContact::create(['matter_id' => $this->matter->id, 'party_id' => $this->salem->id, 'owner_email' => 'main@x.ae', 'owner_name' => 'سالم', 'email' => 'assistant@x.ae']);

        Storage::disk('public')->put('emails/in-1.pdf', '%PDF-in');
        $this->email = MatterEmail::create([
            'matter_id' => $this->matter->id, 'direction' => MatterEmail::RECEIVED, 'status' => MatterEmail::FILED, 'sender_key' => 'iflas',
            'message_id' => 'in-1@x.ae', 'subject' => 'استفسار عن القضية', 'from' => 'سالم <other@x.ae>',
            'to' => ['iflas@jpaemirates.com', 'colleague@x.ae'], 'cc' => ['cc@x.ae'], 'at' => now()->subHour(),
            'files' => [['name' => 'استفسار.pdf', 'path' => 'emails/in-1.pdf']],
        ]);
    }

    public function test_a_reply_starts_from_whoever_wrote_their_partys_own_address_and_the_others_it_reached(): void
    {
        // The sender, and — written from another address of his — the party's own; the others it went to and was
        // copied to, our mailbox left out; the other addresses he brought in, to copy in.
        $this->assertSame(['other@x.ae', 'main@x.ae'], EmailReplySender::to($this->email));
        $this->assertSame(['colleague@x.ae', 'cc@x.ae'], EmailReplySender::cc($this->email));
        $this->assertSame([$this->salem->id], EmailReplySender::partyIds($this->email));
        $this->assertSame('RE: استفسار عن القضية', EmailReplySender::subject($this->email));
        $this->assertSame('Re: x', EmailReplySender::subject(new MatterEmail(['subject' => 'Re: x'])));
        $this->assertTrue(EmailReplySender::hasOriginalPdf($this->email));

        // One of those it reached is among the addresses he brought in: offered to copy in, not put in CC as well.
        MatterContact::create(['matter_id' => $this->matter->id, 'party_id' => $this->salem->id, 'owner_email' => 'main@x.ae', 'owner_name' => 'سالم', 'email' => 'colleague@x.ae']);
        $this->assertSame(['cc@x.ae'], EmailReplySender::cc($this->email->fresh()));

        // Written from his own address: that alone.
        $this->email->update(['from' => 'main@x.ae']);
        $this->assertSame(['main@x.ae'], EmailReplySender::to($this->email->fresh()));
    }

    public function test_the_reply_is_sent_under_the_same_thread_and_kept_beside_the_email_it_answers(): void
    {
        Livewire::test(EmailsRelationManager::class, ['ownerRecord' => $this->matter, 'pageClass' => ViewMatter::class])
            ->mountTableAction('reply', $this->email)
            ->assertSet('mountedActions.0.data.sender', 'iflas')
            ->assertSet('mountedActions.0.data.to', ['other@x.ae', 'main@x.ae'])
            ->assertSet('mountedActions.0.data.cc', ['colleague@x.ae', 'cc@x.ae'])
            ->assertSet('mountedActions.0.data.contacts_cc', ['other@x.ae', 'assistant@x.ae'])
            ->assertSet('mountedActions.0.data.subject', 'RE: استفسار عن القضية')
            ->assertSet('mountedActions.0.data.attach_original', true)
            ->setTableActionData(['body' => '<p>مرفق ما طلبتم.</p>'])
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors()
            ->assertNotified(__('The reply is sent'));

        $this->assertCount(1, $this->sent);
        $reply = $this->sent[0];
        $this->assertSame(['other@x.ae', 'main@x.ae'], array_map(fn ($a) => $a->getAddress(), $reply->getTo()));
        // Copied: the others it reached, and the other address he brought in — not one it goes to already.
        $this->assertSame(['colleague@x.ae', 'cc@x.ae', 'assistant@x.ae'], array_map(fn ($a) => $a->getAddress(), $reply->getCc()));
        $this->assertSame('RE: استفسار عن القضية', $reply->getSubject());
        $this->assertStringContainsString('مرفق ما طلبتم.', $reply->getHtmlBody());
        // Under the email it answers, in their mailbox.
        $this->assertSame('<in-1@x.ae>', $reply->getHeaders()->get('In-Reply-To')?->getBodyAsString());
        $this->assertSame('<in-1@x.ae>', $reply->getHeaders()->get('References')?->getBodyAsString());
        // The email replied to, as its PDF.
        $this->assertSame(['استفسار.pdf'], array_map(fn ($part) => $part->getFilename(), $reply->getAttachments()));

        // Kept on the matter beside the email it answers, as sent, with its PDF.
        $record = MatterEmail::query()->where('direction', MatterEmail::SENT)->sole();
        $this->assertSame([$this->email->id, MatterEmail::BY_REPLY, 'RE: استفسار عن القضية'], [$record->parent_id, $record->matched_by, $record->subject]);
        $this->assertSame(['other@x.ae', 'main@x.ae', 'colleague@x.ae', 'cc@x.ae', 'assistant@x.ae'], $record->to);
        $this->assertCount(1, $this->matter->attachments);
    }

    public function test_the_email_replied_to_may_be_left_out_and_its_absence_is_no_trouble(): void
    {
        Livewire::test(EmailsRelationManager::class, ['ownerRecord' => $this->matter, 'pageClass' => ViewMatter::class])
            ->mountTableAction('reply', $this->email)
            ->setTableActionData(['body' => '<p>شكراً.</p>', 'attach_original' => false, 'cc' => []])
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();
        $this->assertCount(0, $this->sent[0]->getAttachments());
        $this->assertSame(['assistant@x.ae'], array_map(fn ($a) => $a->getAddress(), $this->sent[0]->getCc()));

        // No PDF kept for it: nothing to attach, and not offered.
        $this->email->update(['files' => []]);
        $this->assertFalse(EmailReplySender::hasOriginalPdf($this->email->fresh()));

        // A sent email has no Reply.
        $sent = MatterEmail::query()->where('direction', MatterEmail::SENT)->sole();
        Livewire::test(EmailsRelationManager::class, ['ownerRecord' => $this->matter, 'pageClass' => ViewMatter::class])
            ->assertTableActionHidden('reply', $sent)
            ->assertTableActionVisible('reply', $this->email);
    }
}
