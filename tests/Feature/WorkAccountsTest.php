<?php

namespace Tests\Feature;

use App\Enums\NotificationType;
use App\Enums\RequestStatus;
use App\Enums\RequestType;
use App\Filament\Mms\Pages\UnfiledEmails;
use App\Filament\Support\EmailSendFields;
use App\Models\MailSender;
use App\Models\Matter;
use App\Models\MatterEmail;
use App\Models\MatterParty;
use App\Models\MatterRequest;
use App\Models\Party;
use App\Models\User;
use App\Services\MMS\MatterEmailSentNotifier;
use App\Services\MMS\MatterPeople;
use App\Services\MMS\Requests\RequestServiceFactory;
use App\Services\MMS\SenderMailer;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\MailSenders;
use Tests\TestCase;
use TomatoPHP\FilamentUsers\Filament\Resources\Users\Pages\EditUser;
use TomatoPHP\FilamentUsers\Filament\Resources\Users\Pages\ViewUser;

/**
 * Each user's work account, set once on their page: one Microsoft 365 account for their OneDrive, calendar
 * and own mailbox — read and filed, sent from by them alone; the shared mailboxes theirs too unless kept to
 * their own. An assistant's administrators handle their matters with them and hear of what happens on them.
 */
class WorkAccountsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('mms');
        $this->admin = User::factory()->create(['email' => 'boss@jpa.ae'])->assignRole(Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']));
        $this->actingAs($this->admin);
        MailSenders::add('iflas', ['address' => 'iflas@jpa.ae', 'username' => 'iflas@jpa.ae', 'name' => 'JPA Iflas', 'password' => 'x', 'host' => 'mail.test', 'port' => 587, 'encryption' => 'tls', 'signature' => '<p>JPA</p>']);
    }

    private function assistant(string $email = 'amr@jpa.ae'): User
    {
        $user = User::factory()->create(['email' => $email]);
        Party::factory()->assistant()->create(['user_id' => $user->id, 'onedrive_email' => null]);

        return $user->fresh();
    }

    public function test_the_account_set_once_is_their_onedrive_and_their_own_mailbox(): void
    {
        $amr = $this->assistant();

        Livewire::test(EditUser::class, ['record' => $amr->getKey()])
            ->assertSee(__('Work account'))
            ->assertSee(__('Assistant'))
            ->fillForm(['display_name' => 'Amr', 'ms_account' => 'Amr.Work@jpa.ae', 'mailbox_reads' => true, 'mailbox_sends' => true, 'shared_senders' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        // Shown on their page, as edited.
        Livewire::test(ViewUser::class, ['record' => $amr->getKey()])
            ->assertSee(__('Work account'))
            ->assertSee('amr.work@jpa.ae')
            ->assertSee(__('Assistant'));

        // Their OneDrive follows it.
        $this->assertSame('amr.work@jpa.ae', $amr->party()->first()->onedrive_email);
        // Their own mailbox: Microsoft 365, read and sent from, the office's signature until they set theirs.
        $sender = MailSender::where('user_id', $amr->id)->sole();
        $this->assertSame(['user-'.$amr->id, MailSender::MICROSOFT, 'amr.work@jpa.ae', true, true, true], [$sender->key, $sender->driver, $sender->address, $sender->is_active, $sender->collects, $sender->sends]);
        $this->assertArrayHasKey($sender->key, SenderMailer::collected());
        // Only what concerns a matter — until they choose every email.
        $this->assertFalse($sender->keep_all_inbox);
        $amr->fresh()->update(['mailbox_keeps_all' => true]);
        $this->assertTrue($sender->fresh()->keep_all_inbox);

        // Both switched off: no longer read, nor offered.
        $amr->fresh()->update(['mailbox_reads' => false, 'mailbox_sends' => false]);
        $this->assertFalse($sender->fresh()->is_active);
        $this->assertArrayNotHasKey($sender->key, SenderMailer::all());
    }

    public function test_each_sends_from_their_own_mailbox_and_the_shared_ones_unless_kept_to_their_own(): void
    {
        $amr = $this->assistant();
        $amr->update(['mailbox_sends' => true]);
        $sara = $this->assistant('sara@jpa.ae');
        $sara->update(['mailbox_sends' => true]);

        // Their own first, then the shared one; never a colleague's.
        $this->assertSame(['user-'.$amr->id, 'iflas'], array_keys(SenderMailer::options($amr->fresh())));
        $this->actingAs($amr);
        $this->assertSame('user-'.$amr->id, EmailSendFields::defaultSender());

        // Kept to their own.
        $sara->update(['shared_senders' => false]);
        $this->assertSame(['user-'.$sara->id], array_keys(SenderMailer::options($sara->fresh())));
        // The settings' defaults: the shared ones only.
        $this->assertSame(['iflas'], array_keys(SenderMailer::options(sharedOnly: true)));
    }

    public function test_the_shared_mailboxes_ticked_for_someone_are_the_only_ones_they_send_from_and_see(): void
    {
        MailSenders::add('court', ['address' => 'court@jpa.ae', 'username' => 'court@jpa.ae', 'name' => 'Court Mail', 'password' => 'x', 'host' => 'mail.test', 'port' => 587, 'encryption' => 'tls']);
        $amr = $this->assistant();
        $amr->givePermissionTo(Permission::findOrCreate('View:UnfiledEmails'));
        $amr->update(['unfiled_all' => true]);

        // As before until chosen: all of them, ticked.
        $this->assertNull($amr->shared_mailboxes);
        $this->assertSame(['court', 'iflas'], array_keys(SenderMailer::options($amr)));
        Livewire::test(EditUser::class, ['record' => $amr->getKey()])
            ->assertSchemaStateSet(['shared_mailboxes' => ['court', 'iflas']])
            ->fillForm(['display_name' => 'Amr', 'shared_mailboxes' => ['iflas']])
            ->call('save')
            ->assertHasNoFormErrors();

        $amr = $amr->fresh();
        $this->assertSame(['iflas'], $amr->shared_mailboxes);
        $this->assertSame(['iflas'], array_keys(SenderMailer::options($amr)));
        Livewire::test(ViewUser::class, ['record' => $amr->getKey()])->assertSee('JPA Iflas <iflas@jpa.ae>')->assertDontSee('Court Mail <court@jpa.ae>');

        // The unfiled emails of that one only — not its tab, not its files.
        $email = fn (string $key, string $id): MatterEmail => MatterEmail::create(['direction' => MatterEmail::RECEIVED, 'status' => MatterEmail::PENDING, 'sender_key' => $key,
            'subject' => 'مستندات', 'from' => 'x@y.ae', 'at' => now(), 'message_id' => $id]);
        [$iflas, $court] = [$email('iflas', 'p-1@y.ae'), $email('court', 'p-2@y.ae')];
        Storage::fake('public');
        Storage::disk('public')->put('email-unfiled/'.$court->id.'/email.pdf', '%PDF');
        Storage::disk('public')->put('email-unfiled/'.$iflas->id.'/email.pdf', '%PDF');

        $this->actingAs($amr);
        $page = Livewire::test(UnfiledEmails::class)->assertCanSeeTableRecords([$iflas])->assertCanNotSeeTableRecords([$court]);
        $this->assertArrayNotHasKey('court', $page->instance()->mailboxTabs());
        $this->get('/storage/email-unfiled/'.$court->id.'/email.pdf')->assertForbidden();
        $this->get('/storage/email-unfiled/'.$iflas->id.'/email.pdf')->assertOk();

        // All ticked again: all of them, one added later too.
        $this->actingAs($this->admin);
        Livewire::test(EditUser::class, ['record' => $amr->getKey()])->fillForm(['display_name' => 'Amr', 'shared_mailboxes' => ['court', 'iflas']])->call('save')->assertHasNoFormErrors();
        $this->assertNull($amr->fresh()->shared_mailboxes);
    }

    public function test_what_waits_in_someones_own_mailbox_is_theirs_alone_to_file(): void
    {
        $amr = $this->assistant();
        $amr->update(['mailbox_reads' => true]);
        $amr->givePermissionTo(Permission::findOrCreate('View:UnfiledEmails'), Permission::findOrCreate('ViewOwn:Matter'));
        // A matter that is not theirs, which it may be about.
        $theirs = Matter::factory()->create();
        $email = MatterEmail::create(['direction' => MatterEmail::RECEIVED, 'status' => MatterEmail::PENDING, 'sender_key' => 'user-'.$amr->id,
            'subject' => 'مستندات', 'from' => 'x@y.ae', 'at' => now(), 'candidates' => [$theirs->id], 'message_id' => 'p-1@y.ae']);

        // Not for the super admin.
        Livewire::test(UnfiledEmails::class)->assertCanNotSeeTableRecords([$email]);

        // The owner's to see and file, though the matter is not theirs.
        $this->actingAs($amr->fresh());
        Livewire::test(UnfiledEmails::class)->assertCanSeeTableRecords([$email])
            ->callTableAction('file', $email, ['matter_id' => $theirs->id]);
        $this->assertSame($theirs->id, $email->fresh()->matter_id);
    }

    public function test_an_assistants_administrator_handles_their_matters_and_hears_of_them(): void
    {
        $amr = $this->assistant();
        $manager = User::factory()->create();
        $manager->givePermissionTo(Permission::findOrCreate('ViewOwn:Matter'));
        $amr->party->administrators()->attach($manager);
        $matter = Matter::factory()->create();
        MatterParty::create(['matter_id' => $matter->id, 'party_id' => $amr->party->id, 'role' => 'expert', 'type' => 'assistant']);
        $other = Matter::factory()->create();

        // Theirs to open, and only those.
        $manager = $manager->fresh();
        $this->assertTrue($manager->can('view', $matter));
        $this->assertFalse($manager->can('view', $other));
        $this->assertSame([$matter->id], Matter::query()->ownedBy($manager)->pluck('id')->all());

        // Among the matter's people.
        $this->assertContains($manager->id, MatterPeople::of([$matter->id])->modelKeys());

        // A request decided on it: told, as its assistant is.
        $request = MatterRequest::create(['matter_id' => $matter->id, 'request_by' => $amr->id, 'type' => RequestType::CHANGE_DIFFICULTY, 'status' => RequestStatus::PENDING, 'comment' => 'x', 'extra' => ['new_difficulty' => 'hard']]);
        RequestServiceFactory::make($request)->approve(['approved_comment' => 'ok', 'attachments' => []]);
        $this->assertSame(1, $manager->notifications()->count());

        // An email sent on it by the assistant: told — not the assistant who sent it.
        MatterEmail::recordSent($matter->id, null, 'iflas', 'm-1@jpa.ae', 'إخطار', ['a@b.ae'], $amr->id);
        MatterEmailSentNotifier::flush();
        $this->assertSame(2, $manager->notifications()->count());
        $this->assertSame(0, $amr->notifications()->where('data->viewData->'.NotificationType::DATA_KEY, NotificationType::MATTER_EMAIL_SENT->value)->count());
    }
}
