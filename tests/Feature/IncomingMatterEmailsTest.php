<?php

namespace Tests\Feature;

use App\Enums\ProgressType;
use App\Filament\Mms\Pages\UnfiledEmails;
use App\Models\Matter;
use App\Models\MatterEmail;
use App\Models\MatterOneDriveFolder;
use App\Models\MatterParty;
use App\Models\Party;
use App\Models\User;
use App\Services\MMS\IncomingEmailMatcher;
use App\Services\MMS\MatterReplyCollector;
use App\Services\MMS\SentFolder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Mockery;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Any email from a matter's party — not only replies — kept on its matter
 * when Wakeel is sure which: by its number in the subject or text, a party's
 * name in it, or the sender having only the one matter. Else it waits for a
 * person to choose.
 */
class IncomingMatterEmailsTest extends TestCase
{
    use RefreshDatabase;

    private Matter $first;

    private Matter $second;

    private Party $lawyer;

    private User $user;

    /** @var list<string> OneDrive files put */
    private array $uploads = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->withoutDefer();
        \Tests\Support\MailSenders::add('iflas', ['username' => 'iflas@jpa.ae', 'address' => 'iflas@jpa.ae', 'name' => 'JPA', 'password' => 'x', 'host' => 'mail.test', 'port' => 587, 'encryption' => 'tls']);
        config([
            'services.outlook' => ['tenant_id' => 'tenant', 'client_id' => 'client', 'client_secret' => 'secret'],
        ]);
        $this->app->instance(SentFolder::class, Mockery::spy(SentFolder::class));
        Gate::before(fn () => true);
        Filament::setCurrentPanel(Filament::getPanel('mms'));

        Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        $this->user = User::factory()->create()->assignRole('super-admin');
        $this->actingAs($this->user);

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'login.microsoftonline.com')) {
                return Http::response(['access_token' => 'token']);
            }
            if ($request->method() === 'PUT') {
                $this->uploads[] = rawurldecode($request->url());
            }

            return Http::response(['id' => 'folder-id', 'webUrl' => 'https://od/x'], 201);
        });

        // A lawyer on two matters, each against someone else.
        $this->lawyer = Party::factory()->create(['name' => 'مكتب البنا', 'email' => ['Office@AlBanna.ae']]);
        $this->first = $this->matterWith('986', 'شركة المهاد للتجارة');
        $this->second = $this->matterWith('987', 'مؤسسة النخيل');
    }

    private function matterWith(string $number, string $against): Matter
    {
        $matter = Matter::factory()->create(['number' => $number, 'year' => '2026']);
        MatterParty::create(['matter_id' => $matter->id, 'party_id' => $this->lawyer->id, 'role' => 'representative', 'type' => 'plaintiff']);
        MatterParty::create(['matter_id' => $matter->id, 'party_id' => Party::factory()->create(['name' => $against])->id, 'role' => 'party', 'type' => 'defendant']);
        MatterOneDriveFolder::create([
            'matter_id' => $matter->id, 'folder_name' => $number.'-2026', 'status' => MatterOneDriveFolder::CREATED, 'drive_item_id' => 'root-'.$number,
            'party_id' => Party::factory()->assistant()->create(['onedrive_email' => 'amr@firm.ae'])->id,
        ]);

        return $matter;
    }

    private function email(array $attributes = []): array
    {
        return [
            'message_id' => '<m-'.uniqid().'@albanna.ae>', 'references' => [], 'subject' => 'مستندات', 'from' => 'office@albanna.ae', 'from_name' => 'مكتب البنا',
            'to' => ['iflas@jpa.ae'], 'cc' => [], 'at' => now()->toDateTimeString(), 'body' => '<p>مرفق المستندات.</p>',
            'attachments' => [['name' => 'عقد.pdf', 'contents' => '%PDF-contract', 'mime' => 'application/pdf']],
            ...$attributes,
        ];
    }

    private function collect(array ...$emails): int
    {
        return app(MatterReplyCollector::class)->handle($emails, collect(), 'iflas');
    }

    public function test_the_matter_number_in_the_subject_or_text_says_which(): void
    {
        $this->assertSame(2, $this->collect(
            $this->email(['subject' => 'مستندات القضية 987/2026']),
            $this->email(['body' => '<p>بخصوص الدعوى رقم 986 لسنة 2026 مرفق العقد.</p>']),
        ));

        $second = MatterEmail::query()->where('matter_id', $this->second->id)->sole();
        $this->assertSame([MatterEmail::FILED, MatterEmail::BY_SUBJECT], [$second->status, $second->matched_by]);
        $this->assertSame(MatterEmail::BY_BODY, MatterEmail::query()->where('matter_id', $this->first->id)->sole()->matched_by);

        // Its PDF on the matter and in OneDrive; the file it came with in OneDrive only — listed, not kept here.
        $this->assertSame([$second->files[0]['name']], $this->second->attachments()->pluck('name')->all());
        $this->assertStringContainsString(__('Incoming email'), $second->files[0]['name']);
        $this->assertContains('عقد.pdf', array_column($second->files, 'name'));
        $this->assertNull(collect($second->files)->firstWhere('name', 'عقد.pdf')['path']);
        $this->assertTrue(collect($this->uploads)->contains(fn (string $url) => str_contains($url, 'عقد.pdf')));
        $this->assertSame([], array_values(array_filter(Storage::disk('public')->allFiles(), fn (string $p) => str_ends_with($p, '.pdf') && ! str_contains($p, 'attachments/emails'))));
        $step = $this->second->progress()->sole();
        $this->assertSame(ProgressType::EMAIL_RECEIVED, $step->type);
        $this->assertSame('مستندات القضية 987/2026', $step->title);

        // Read again: kept once.
        $this->assertSame(0, $this->collect($this->email(['message_id' => '<'.$second->message_id.'>'])));
    }

    public function test_the_other_partys_name_says_which(): void
    {
        $this->collect($this->email(['body' => '<p>ردنا على مذكرة مؤسسة  النخيل.</p>']));

        $email = MatterEmail::sole();
        $this->assertSame([$this->second->id, MatterEmail::BY_PARTY_NAME], [$email->matter_id, $email->matched_by]);
    }

    public function test_a_sender_on_several_matters_with_nothing_to_say_which_waits_for_a_person(): void
    {
        $this->collect($this->email());

        $email = MatterEmail::sole();
        $this->assertNull($email->matter_id);
        $this->assertSame(MatterEmail::PENDING, $email->status);
        $this->assertEqualsCanonicalizing([$this->first->id, $this->second->id], $email->candidates);
        // Nothing on either matter yet; its PDF and file kept aside.
        $this->assertSame(0, $this->first->attachments()->count() + $this->second->attachments()->count());
        $this->assertSame([], $this->uploads);
        $this->assertCount(2, $email->files);
        $this->assertTrue(Storage::disk('public')->exists($email->files[1]['path']));
        // Shown to whoever has one of its matters.
        $this->assertSame(1, MatterEmail::pending([$this->second->id])->count());
        $this->assertSame(0, MatterEmail::pending([$this->second->id + 100])->count());

        // The matters it may be about, as a bullet list — in the table and in the note to the people who can choose.
        Livewire::test(UnfiledEmails::class)
            ->assertTableColumnExists('candidates', fn ($column) => $column->isBulleted() && $column->isListWithLineBreaks() && ! $column->isBadge(), $email);
        $body = \App\Services\MMS\MatterReplyCollector::bullets(['Subject', new \Illuminate\Support\HtmlString('It may be about:'.\App\Services\MMS\MatterReplyCollector::bullets(['2026/1', '2026/2'])->toHtml())])->toHtml();
        $this->assertStringContainsString('<li>It may be about:<ul', $body);
        $this->assertSame(2, substr_count($body, '<ul'));

        // A person chooses.
        Livewire::test(UnfiledEmails::class)
            ->assertCanSeeTableRecords([$email])
            ->callTableAction('file', $email, ['matter_id' => $this->first->id])
            ->assertHasNoTableActionErrors();

        $email->refresh();
        $this->assertSame([$this->first->id, MatterEmail::FILED, MatterEmail::BY_HAND], [$email->matter_id, $email->status, $email->matched_by]);
        // The PDF with the matter's attachments; the file it came with in OneDrive only.
        $this->assertNotContains('عقد.pdf', $this->first->attachments()->pluck('name')->all());
        $this->assertSame(1, $this->first->attachments()->count());
        $this->assertContains('عقد.pdf', array_column($email->files, 'name'));
        $this->assertSame(ProgressType::EMAIL_RECEIVED, $this->first->progress()->sole()->type);
        $this->assertCount(2, $this->uploads);
        $this->assertTrue(collect($this->uploads)->contains(fn (string $url) => str_contains($url, 'عقد.pdf')));
        // Moved, not copied.
        $this->assertSame([], Storage::disk('public')->allFiles('email-unfiled'));
    }

    public function test_one_about_no_matter_is_set_aside(): void
    {
        $this->collect($this->email());
        $email = MatterEmail::sole();

        Livewire::test(UnfiledEmails::class)->callTableAction('dismiss', $email);

        $this->assertSame(MatterEmail::DISMISSED, $email->refresh()->status);
        $this->assertSame([], Storage::disk('public')->allFiles('email-unfiled'));
        Livewire::test(UnfiledEmails::class)->assertCanNotSeeTableRecords([$email]);
    }

    public function test_a_sender_with_one_matter_is_that_matters_unless_the_email_names_another(): void
    {
        MatterParty::query()->where('matter_id', $this->second->id)->where('party_id', $this->lawyer->id)->delete();

        $this->collect($this->email());
        $this->assertSame([$this->first->id, MatterEmail::BY_ADDRESS], [MatterEmail::sole()->matter_id, MatterEmail::sole()->matched_by]);

        // Its subject names a matter that is not theirs, the only one with that number: it is that matter's.
        $this->collect($this->email(['subject' => 'بخصوص 987/2026', 'message_id' => '<other-1@albanna.ae>']));
        $byNumber = MatterEmail::query()->latest('id')->first();
        $this->assertSame([$this->second->id, MatterEmail::FILED, MatterEmail::BY_SUBJECT], [$byNumber->matter_id, $byNumber->status, $byNumber->matched_by]);

        // Only in the text, the only one named: also that matter's.
        $this->collect($this->email(['body' => '<p>بخصوص 987/2026</p>', 'message_id' => '<other-2@albanna.ae>']));
        $byText = MatterEmail::query()->latest('id')->first();
        $this->assertSame([$this->second->id, MatterEmail::BY_BODY], [$byText->matter_id, $byText->matched_by]);

        // Two matters named in the text, neither theirs: not sure — a person decides.
        Matter::factory()->create(['number' => '1500', 'year' => '2026']);
        Matter::factory()->create(['number' => '1501', 'year' => '2026']);
        $this->collect($this->email(['body' => '<p>بخصوص 1500/2026 و 1501/2026</p>', 'message_id' => '<other-3@albanna.ae>']));
        $this->assertSame(MatterEmail::PENDING, MatterEmail::query()->latest('id')->first()->status);
    }

    /** An assistant (a user with a profile) on the matter. */
    private function assistantOn(Matter $matter): User
    {
        $user = User::factory()->create();
        $party = Party::factory()->assistant()->create(['user_id' => $user->id]);
        MatterParty::create(['matter_id' => $matter->id, 'party_id' => $party->id, 'role' => 'expert', 'type' => 'assistant']);

        return $user;
    }

    public function test_an_email_that_arrives_is_told_to_the_assistant_and_the_super_admins_by_default(): void
    {
        $assistant = $this->assistantOn($this->second);
        $otherAdmin = User::factory()->create()->assignRole('super-admin');
        $stranger = User::factory()->create();

        // Filed by itself: the number in the subject.
        $this->collect($this->email(['subject' => 'مستندات القضية 987/2026']));

        $this->assertSame(1, $assistant->notifications()->count());
        $this->assertSame(1, $this->user->notifications()->count());
        $this->assertSame(1, $otherAdmin->notifications()->count());
        $this->assertSame(0, $stranger->notifications()->count());

        // Waiting for a person to choose: the assistants of the matters it may be about, and the super admins, are asked.
        $this->assistantOn($this->first);
        $this->collect($this->email(['message_id' => '<pending-1@albanna.ae>']));
        $this->assertSame(2, $otherAdmin->notifications()->count());
        $this->assertTrue($otherAdmin->notifications()->get()->contains(fn ($note) => str_starts_with((string) ($note->data['title'] ?? ''), 'Which matter is this email from')));
    }

    public function test_filing_by_hand_tells_the_people_of_the_matter_but_not_who_filed_it(): void
    {
        $assistant = $this->assistantOn($this->first);
        $this->collect($this->email());
        $email = MatterEmail::sole();
        $assistant->notifications()->delete();
        $this->user->notifications()->delete();
        $otherAdmin = User::factory()->create()->assignRole('super-admin');

        Livewire::test(UnfiledEmails::class)->callTableAction('file', $email, ['matter_id' => $this->first->id]);

        $this->assertSame(1, $assistant->notifications()->count());
        $this->assertSame(1, $otherAdmin->notifications()->count());
        // $this->user filed it.
        $this->assertSame(0, $this->user->notifications()->count());
    }

    public function test_the_matter_lists_its_emails_sent_and_received_each_to_open(): void
    {
        $this->collect($this->email(['subject' => 'مستندات القضية 987/2026']));
        MatterEmail::recordSent($this->second->id, null, 'iflas', 'sent-1', 'خطاب إلى المحكمة', ['court@dubai.ae'], $this->user->id);
        // Waiting for a matter, and set aside: not on any.
        $this->collect($this->email(['message_id' => '<pending-1@albanna.ae>']));
        $received = MatterEmail::where('direction', MatterEmail::RECEIVED)->where('matter_id', $this->second->id)->sole();
        $sent = MatterEmail::where('direction', MatterEmail::SENT)->sole();

        $page = Livewire::test(\App\Filament\Mms\Resources\Matters\RelationManagers\EmailsRelationManager::class, [
            'ownerRecord' => $this->second,
            'pageClass' => \App\Filament\Mms\Resources\Matters\Pages\ViewMatter::class,
        ]);

        $page->assertCanSeeTableRecords([$received, $sent])
            ->assertCountTableRecords(2)
            ->filterTable('direction', MatterEmail::RECEIVED)
            ->assertCanSeeTableRecords([$received])
            ->assertCanNotSeeTableRecords([$sent])
            ->removeTableFilters();

        // What of each is in OneDrive: the PDF, the original (.eml), the files it came with.
        $page->assertTableColumnStateSet('pdf_saved', 'Saved', $received)
            ->assertTableColumnStateSet('eml_saved', 'Not saved', $received)
            ->assertTableColumnStateSet('attachments_saved', 'Saved', $received)
            // One that went out: its PDF and what it went with — not yet noted here; it has no original (.eml).
            ->assertTableColumnStateSet('pdf_saved', 'Not checked', $sent)
            ->assertTableColumnStateSet('eml_saved', null, $sent)
            ->assertTableColumnStateSet('attachments_saved', 'Not checked', $sent);

        // One that came with nothing; one not saved; one never checked.
        $plain = (new MatterEmail)->forceFill(['direction' => MatterEmail::RECEIVED, 'files' => [['name' => 'a.pdf', 'path' => 'x']], 'onedrive' => ['pdf' => true, 'eml' => false, 'attachments' => 0]]);
        $this->assertSame('Without attachment', $plain->oneDriveLabel('attachments'));
        $with = (clone $plain)->forceFill(['files' => [['name' => 'a.pdf', 'path' => 'x'], ['name' => 'b.pdf', 'path' => 'y'], ['name' => 'c.pdf', 'path' => 'z']]]);
        $this->assertSame('Not saved', $with->oneDriveLabel('attachments'));
        $this->assertSame('Partly saved', $with->forceFill(['onedrive' => ['attachments' => 1]])->oneDriveLabel('attachments'));
        $this->assertSame('Saved', $with->forceFill(['onedrive' => ['attachments' => 2]])->oneDriveLabel('attachments'));
        $this->assertSame('Not checked', $with->forceFill(['onedrive' => null])->oneDriveLabel('attachments'));
        $this->assertSame('Not checked', $with->oneDriveLabel('pdf'));
        // The PDF of one that came in opens; its details list the files it came with.
        $page->assertTableActionVisible('open', $received)
            ->assertTableActionHidden('open', $sent)
            ->assertTableActionExists('view', record: $received);

        $details = view('filament.mms.matter-email', [
            'email' => $received->load('user'),
            'files' => [['name' => 'x.pdf', 'url' => 'https://x/x.pdf'], ['name' => 'عقد.pdf', 'url' => 'https://x/c.pdf']],
        ])->render();
        $this->assertStringContainsString('عقد.pdf', $details);
        $this->assertMatchesRegularExpression('/PDF in OneDrive.*?Saved.*?EML in OneDrive.*?Not saved.*?Attachments in OneDrive.*?Saved/s', $details);
        $this->assertStringContainsString(__('the email as PDF'), $details);
        $this->assertStringContainsString(e($received->from), $details);
        $this->assertStringContainsString('/storage/attachments/emails/'.$this->second->id, Storage::disk('public')->url($received->files[0]['path']));
    }
    public function test_a_collected_email_is_marked_read_in_the_mailbox_and_its_original_is_kept_as_eml(): void
    {
        $read = [];
        $mark = function (string $which) use (&$read): \Closure {
            return function () use (&$read, $which): void {
                $read[] = $which;
            };
        };
        $eml = "From: office@albanna.ae\r\nSubject: =?UTF-8?B?2YXYs9iq2YbYr9in2Ko=?=\r\n\r\nbody";

        // Filed by itself, waiting for a person, and not about any matter: only the first two are collected.
        $this->collect(
            $this->email(['subject' => 'مستندات 987/2026', 'raw' => fn () => $eml, 'mark_read' => $mark('filed')]),
            $this->email(['message_id' => '<held@albanna.ae>', 'raw' => fn () => $eml, 'mark_read' => $mark('held')]),
            $this->email(['message_id' => '<stranger@x.com>', 'from' => 'nobody@x.com', 'raw' => fn () => $eml, 'mark_read' => $mark('stranger')]),
        );

        $this->assertEqualsCanonicalizing(['filed', 'held'], $read);

        // The original email goes to OneDrive only, beside its PDF — not into the system.
        $filed = MatterEmail::where('status', MatterEmail::FILED)->sole();
        $this->assertNull(collect($filed->files)->firstWhere('kind', MatterEmail::SOURCE));
        $this->assertSame([], $this->second->attachments()->where('name', 'like', '%.eml')->pluck('name')->all());
        // (Only the held one, aside for now.)
        $this->assertSame([], array_values(array_filter(Storage::disk('public')->allFiles(), fn (string $path) => str_ends_with($path, '.eml') && ! str_starts_with($path, 'email-unfiled'))));
        $this->assertTrue(collect($this->uploads)->contains(fn (string $url) => str_contains($url, '.eml:/content')));
        $this->assertCount(1, $filed->attachmentFiles());
        $this->assertSame(['pdf' => true, 'eml' => true, 'attachments' => 1], collect($filed->onedrive)->except('folder')->all());

        // Held: kept aside with it for the time being, and filed by hand, it goes to OneDrive and is gone from the system.
        $held = MatterEmail::where('status', MatterEmail::PENDING)->sole();
        $this->assertSame('source', collect($held->files)->last()['kind']);
        $uploads = count($this->uploads);
        Livewire::test(UnfiledEmails::class)->callTableAction('file', $held, ['matter_id' => $this->first->id]);

        $held->refresh();
        $this->assertSame(['pdf' => true, 'eml' => true, 'attachments' => 1], collect($held->onedrive)->except('folder')->all());
        $this->assertNull(collect($held->files)->firstWhere('kind', 'source'));
        $this->assertSame([], $this->first->attachments()->where('name', 'like', '%.eml')->pluck('name')->all());
        $this->assertCount($uploads + 3, $this->uploads);
        $this->assertTrue(collect(array_slice($this->uploads, $uploads))->contains(fn (string $url) => str_contains($url, '.eml:/content')));
        $this->assertSame([], Storage::disk('public')->allFiles('email-unfiled'));
        $this->assertCount(1, $held->attachmentFiles());
    }

    public function test_an_email_whose_mailbox_refuses_marking_read_is_still_kept(): void
    {
        $this->collect($this->email([
            'subject' => 'مستندات 987/2026',
            'mark_read' => fn () => throw new \RuntimeException('Microsoft 365 refused'),
        ]));

        $this->assertSame(MatterEmail::FILED, MatterEmail::sole()->status);
    }

    public function test_onedrive_can_be_read_for_what_of_an_email_is_there(): void
    {
        $this->collect($this->email(['subject' => 'مستندات القضية 987/2026']));
        $email = MatterEmail::where('status', MatterEmail::FILED)->sole();
        $stem = pathinfo($email->files[0]['name'], PATHINFO_FILENAME);
        $received = \App\Services\MMS\MatterOneDriveFolders::receivedEmailsFolder();
        $email->update(['onedrive' => null]);

        // OneDrive has the PDF and the file it came with — not the original.
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(function (Request $request) use ($stem, $received) {
            $url = rawurldecode($request->url());

            return match (true) {
                str_contains($url, 'login.microsoftonline.com') => Http::response(['access_token' => 'token']),
                str_contains($url, '/items/root-987/children') => Http::response(['value' => [['id' => 'recv', 'name' => $received, 'folder' => ['childCount' => 2]]]]),
                str_contains($url, '/items/recv/children') => Http::response(['value' => [
                    ['id' => 'f1', 'name' => $stem.'.pdf', 'file' => ['mimeType' => 'application/pdf']],
                    ['id' => 'sub', 'name' => $stem, 'folder' => ['childCount' => 1]],
                ]]),
                str_contains($url, '/items/sub/children') => Http::response(['value' => [['id' => 'a1', 'name' => 'عقد.pdf', 'file' => ['mimeType' => 'application/pdf']]]]),
                default => Http::response(['value' => []]),
            };
        });

        $this->assertTrue(app(\App\Services\MMS\MatterOneDriveFolders::class)->checkEmails($this->second, [$email]));

        $email->refresh();
        $this->assertSame([true, false, 1], [$email->onedrive['pdf'], $email->onedrive['eml'], $email->onedrive['attachments']]);
        $this->assertNotNull($email->onedrive['checked_at']);
        $this->assertSame(['Saved', 'Not saved', 'Saved'], [$email->oneDriveLabel('pdf') === 'Saved' ? 'Saved' : 'x', $email->oneDriveLabel('eml'), $email->oneDriveLabel('attachments')]);

        // No OneDrive folder on the matter: nothing to read, nothing changed.
        MatterOneDriveFolder::query()->where('matter_id', $this->second->id)->delete();
        $this->assertFalse(app(\App\Services\MMS\MatterOneDriveFolders::class)->checkEmails($this->second, [$email]));
    }

    public function test_an_email_is_saved_in_the_folder_structure_set_for_the_matter(): void
    {
        $this->second->update(['custom_fields' => ['phase' => 'التنفيذ']]);
        \App\Models\Setting::set(\App\Services\MMS\MatterOneDriveFolders::RECEIVED_EMAILS, '{{matter.custom.phase}}/01 system + 02/{{matter.plaintiff_capacity}}', 'onedrive');
        \App\Models\Setting::clearCache();

        $this->collect($this->email(['subject' => 'مستندات القضية 987/2026']));

        // The folders made inside the matter's folder, one in the other, in that order.
        $made = Http::recorded()
            ->filter(fn ($pair) => $pair[0]->method() === 'POST' && str_contains($pair[0]->url(), '/children'))
            ->map(fn ($pair) => $pair[0]->data()['name'] ?? null)
            ->values()
            ->all();
        $capacity = \App\Models\Type::sideLabel($this->second->type, 'plaintiff');
        $this->assertSame(['التنفيذ', '01 system + 02', $capacity], array_slice($made, 0, 3));

        // And what is saved there is found there.
        $email = MatterEmail::where('status', MatterEmail::FILED)->sole();
        $this->assertSame(['pdf' => true, 'eml' => false, 'attachments' => 1], collect($email->onedrive)->except('folder')->all());
    }

    public function test_emails_of_a_day_are_saved_in_that_days_folder(): void
    {
        \App\Models\Setting::set(\App\Services\MMS\MatterOneDriveFolders::RECEIVED_EMAILS, 'واردة/{{day}}', 'onedrive');
        \App\Models\Setting::clearCache();

        $this->collect(
            $this->email(['subject' => 'القضية 987/2026 أولى', 'at' => '2026-10-16 09:00:00']),
            $this->email(['subject' => 'القضية 987/2026 ثانية', 'at' => '2026-10-16 15:00:00', 'message_id' => '<two@albanna.ae>']),
            $this->email(['subject' => 'القضية 987/2026 ثالثة', 'at' => '2026-10-17 09:00:00', 'message_id' => '<three@albanna.ae>']),
        );

        $made = Http::recorded()
            ->filter(fn ($pair) => $pair[0]->method() === 'POST' && str_contains($pair[0]->url(), '/children'))
            ->map(fn ($pair) => $pair[0]->data()['name'] ?? null)
            ->unique()
            ->values()
            ->all();
        $this->assertContains('2026-10-16', $made);
        $this->assertContains('2026-10-17', $made);

        // Each in its day's folder when checked, too.
        $emails = MatterEmail::where('status', MatterEmail::FILED)->orderBy('at')->get();
        $this->assertSame(
            ['واردة/2026-10-16', 'واردة/2026-10-16', 'واردة/2026-10-17'],
            $emails->map(fn (MatterEmail $e) => \App\Services\MMS\MatterOneDriveFolders::receivedEmailsFolderFor($this->second, $e->at))->all(),
        );
    }

    public function test_with_only_the_day_as_the_structure_the_email_goes_in_that_days_folder_not_the_main_folder(): void
    {
        $od = (new \Tests\Support\FakeOneDrive)->install();
        // The matter's folder, really there.
        $main = $od->ensure('Work/Matters/2026-987');
        \App\Models\MatterOneDriveFolder::where('matter_id', $this->second->id)->update(['drive_item_id' => $main]);
        \App\Models\Setting::set(\App\Services\MMS\MatterOneDriveFolders::RECEIVED_EMAILS, '{{day}}', 'onedrive');
        \App\Models\Setting::clearCache();

        $this->collect($this->email(['subject' => 'مستندات القضية 987/2026', 'at' => '2026-10-16 09:00:00']));

        $files = array_values(array_filter($od->tree(), fn (string $path) => str_contains($path, '.pdf')));
        $this->assertNotEmpty($files, 'nothing was saved in OneDrive');
        // Every file is under that day's folder — none directly in the matter's folder.
        foreach ($files as $path) {
            $this->assertStringStartsWith('Work/Matters/2026-987/2026-10-16/', $path);
        }
    }

    public function test_with_no_onedrive_folder_the_file_it_came_with_is_kept_in_the_system_so_it_is_not_lost(): void
    {
        \App\Models\MatterOneDriveFolder::query()->where('matter_id', $this->second->id)->delete();

        $this->collect($this->email(['subject' => 'مستندات القضية 987/2026']));

        $email = MatterEmail::where('matter_id', $this->second->id)->sole();
        $contract = collect($email->files)->firstWhere('name', 'عقد.pdf');
        $this->assertNotNull($contract['path']);
        $this->assertTrue(Storage::disk('public')->exists($contract['path']));
        $this->assertContains('عقد.pdf', $this->second->attachments()->pluck('name')->all());
        $this->assertSame('Not saved', $email->oneDriveLabel('attachments'));
    }

    public function test_a_matter_with_no_onedrive_folder_gets_the_email_to_download_by_hand(): void
    {
        \App\Models\Setting::set(\App\Services\MMS\MatterOneDriveFolders::ENABLED, true, 'onedrive');
        MatterOneDriveFolder::query()->where('matter_id', $this->second->id)->delete();
        // Someone to tell: a person of the matter.
        $user = User::factory()->create();
        $party = Party::factory()->create(['user_id' => $user->id]);
        MatterParty::create(['matter_id' => $this->second->id, 'party_id' => $party->id, 'role' => 'expert', 'type' => 'certified']);

        $this->collect($this->email(['subject' => 'مستندات القضية 987/2026']));

        $notice = $user->notifications()->sole();
        $actions = array_column($notice->data['actions'], 'name');
        $this->assertContains('download', $actions);
        $download = collect($notice->data['actions'])->firstWhere('name', 'download');
        $this->assertStringContainsString('storage/attachments/emails/'.$this->second->id, $download['url']);
        $this->assertStringContainsString('Not saved in OneDrive', $notice->data['body']);
    }

    public function test_a_sender_on_several_matters_writing_the_number_of_one_they_are_not_on_is_filed_there_by_the_subject(): void
    {
        $third = Matter::factory()->create(['number' => '1500', 'year' => '2026']);

        $this->collect($this->email(['subject' => 'رد: الدعوى رقم 1500 لسنة 2026 إجراءات الإعسار - دبي الابتدائية / إشعار بمأمورية الخبرة وطلب مستندات']));

        $email = MatterEmail::sole();
        $this->assertSame([$third->id, MatterEmail::FILED, MatterEmail::BY_SUBJECT], [$email->matter_id, $email->status, $email->matched_by]);

        // Two matters share the number and year: which? — a person decides, the sender's matters or not.
        Matter::factory()->create(['number' => '1500', 'year' => '2026']);
        $this->collect($this->email(['subject' => 'بخصوص 1500/2026', 'message_id' => '<dup@albanna.ae>']));
        $held = MatterEmail::query()->latest('id')->first();
        $this->assertSame(MatterEmail::PENDING, $held->status);
        // …among the matters with that number.
        $this->assertCount(2, $held->candidates);
        $this->assertContains($third->id, $held->candidates);
    }

    public function test_only_a_partys_emails_are_kept(): void
    {
        // A stranger; one of the office's experts; the office's own mailbox; one with no id.
        $expert = Party::factory()->create(['email' => ['expert@jpa.ae']]);
        MatterParty::create(['matter_id' => $this->first->id, 'party_id' => $expert->id, 'role' => 'expert', 'type' => 'certified']);

        $this->assertSame(0, $this->collect(
            $this->email(['from' => 'someone@gmail.com', 'subject' => 'مرحبا']),
            $this->email(['from' => 'expert@jpa.ae', 'subject' => '986/2026']),
            $this->email(['from' => 'iflas@jpa.ae', 'subject' => '986/2026']),
            $this->email(['message_id' => '', 'subject' => '986/2026']),
        ));
        $this->assertSame(0, MatterEmail::count());
    }

    public function test_someone_on_no_matter_naming_a_matter_is_filed_on_it(): void
    {
        $number = fn (Matter $m): string => $m->number.'/'.$m->year;

        // In the subject; else in the text.
        $this->collect(
            $this->email(['from' => 'clerk@court.ae', 'subject' => 'بخصوص الدعوى '.$number($this->first)]),
            $this->email(['from' => 'newlawyer@x.ae', 'subject' => 'مستندات', 'body' => '<p>الدعوى رقم '.$number($this->second).'</p>']),
        );
        $byFrom = fn (string $address) => MatterEmail::query()->where('from', 'like', '%'.$address.'%')->sole();
        $this->assertSame([$this->first->id, MatterEmail::FILED, MatterEmail::BY_SUBJECT], [$byFrom('clerk@court.ae')->matter_id, $byFrom('clerk@court.ae')->status, $byFrom('clerk@court.ae')->matched_by]);
        $this->assertSame([$this->second->id, MatterEmail::BY_BODY], [$byFrom('newlawyer@x.ae')->matter_id, $byFrom('newlawyer@x.ae')->matched_by]);

        // A number two matters share: a person picks among those two.
        $twin = Matter::factory()->create(['number' => $this->first->number, 'year' => $this->first->year]);
        $this->collect($this->email(['from' => 'other@court.ae', 'subject' => 'بخصوص '.$number($this->first)]));
        $held = $byFrom('other@court.ae');
        $this->assertSame(MatterEmail::PENDING, $held->status);
        $this->assertEqualsCanonicalizing([$this->first->id, $twin->id], array_map('intval', (array) $held->candidates));
    }

    public function test_someone_on_no_matter_naming_a_matters_party_by_name_or_email_is_filed_on_it(): void
    {
        // The defendant's name in the text.
        $this->collect($this->email(['from' => 'clerk@court.ae', 'subject' => 'إخطار', 'body' => '<p>بشأن شركة المهاد للتجارة</p>']));
        $byName = MatterEmail::query()->latest('id')->first();
        $this->assertSame([$this->first->id, MatterEmail::FILED, MatterEmail::BY_PARTY_NAME], [$byName->matter_id, $byName->status, $byName->matched_by]);

        // The defendant's email address in the text.
        $defendant = $this->second->parties()->wherePivot('role', 'party')->firstOrFail();
        $defendant->update(['email' => ['legal@nakheel.ae']]);
        $this->collect($this->email(['from' => 'bank@x.ae', 'subject' => 'كشف حساب', 'body' => '<p>المرسل إلى legal@nakheel.ae</p>', 'message_id' => '<bank@x.ae>']));
        $this->assertSame($this->second->id, MatterEmail::query()->latest('id')->first()->matter_id);

        // A name on both: a person picks among the two.
        MatterParty::create(['matter_id' => $this->second->id, 'party_id' => $this->first->parties()->wherePivot('role', 'party')->firstOrFail()->id, 'role' => 'party', 'type' => 'defendant']);
        $this->collect($this->email(['from' => 'other@court.ae', 'subject' => 'إخطار', 'body' => '<p>شركة المهاد للتجارة</p>', 'message_id' => '<two@court.ae>']));
        $held = MatterEmail::query()->latest('id')->first();
        $this->assertSame(MatterEmail::PENDING, $held->status);
        $this->assertEqualsCanonicalizing([$this->first->id, $this->second->id], array_map('intval', (array) $held->candidates));
    }

    public function test_an_email_filed_on_the_wrong_matter_is_moved_with_its_files_and_progress(): void
    {
        $od = (new \Tests\Support\FakeOneDrive)->install();
        MatterOneDriveFolder::where('matter_id', $this->first->id)->update(['drive_item_id' => $od->ensure('Work/2026-986')]);
        MatterOneDriveFolder::where('matter_id', $this->second->id)->update(['drive_item_id' => $od->ensure('Work/2026-987')]);
        \App\Models\Setting::set(\App\Services\MMS\MatterOneDriveFolders::RECEIVED_EMAILS, 'واردة', 'onedrive');
        \App\Models\Setting::clearCache();

        $this->collect($this->email(['subject' => 'مستندات القضية 986/2026']));
        $email = MatterEmail::sole();
        $this->assertSame($this->first->id, $email->matter_id);
        $inFirst = $od->names('Work/2026-986/واردة');
        $this->assertNotEmpty($inFirst);

        Livewire::test(\App\Filament\Mms\Resources\Matters\RelationManagers\EmailsRelationManager::class, [
            'ownerRecord' => $this->first,
            'pageClass' => \App\Filament\Mms\Resources\Matters\Pages\ViewMatter::class,
        ])->callTableAction('move', $email, ['matter_id' => $this->second->id])->assertHasNoTableActionErrors();

        $email->refresh();
        $this->assertSame([$this->second->id, MatterEmail::BY_HAND], [$email->matter_id, $email->matched_by]);
        // Its OneDrive files: out of the first matter's folder, into the other's.
        $this->assertSame([], $od->names('Work/2026-986/واردة'));
        $this->assertSame($inFirst, $od->names('Work/2026-987/واردة'));
        $this->assertTrue($email->onedrive['pdf']);
        // Its PDF with the other matter's attachments; its step in the other's progress.
        $this->assertSame(0, $this->first->attachments()->where('path', $email->files[0]['path'])->count());
        $this->assertSame(1, $this->second->attachments()->where('path', $email->files[0]['path'])->count());
        $this->assertSame([$this->second->id], \App\Models\MatterProgress::query()->where('source_id', $email->id)->where('source_type', $email->getMorphClass())->pluck('matter_id')->all());
    }

    public function test_set_to_keep_every_email_a_strangers_goes_to_unfiled_to_file_on_any_matter(): void
    {
        \App\Models\Setting::set(MatterReplyCollector::KEEP_ALL, true, 'mail');

        // A stranger's; still never the office's own mailbox's, nor one with no id.
        $this->collect(
            $this->email(['from' => 'someone@gmail.com', 'subject' => 'استفسار']),
            $this->email(['from' => 'iflas@jpa.ae', 'subject' => 'استفسار', 'message_id' => '<own@jpa.ae>']),
            $this->email(['from' => 'other@gmail.com', 'message_id' => '']),
        );

        $email = MatterEmail::sole();
        $this->assertSame([MatterEmail::PENDING, []], [$email->status, (array) $email->candidates]);
        $this->assertStringContainsString('someone@gmail.com', $email->from);
        Livewire::test(UnfiledEmails::class)->assertCanSeeTableRecords([$email]);

        // A tab for each mailbox of the senders list — the one that received the email.
        $other = app(MatterReplyCollector::class);
        $other->handle([$this->email(['from' => 'third@gmail.com', 'message_id' => '<third@gmail.com>'])], collect(), 'noreply');
        $third = MatterEmail::query()->where('sender_key', 'noreply')->sole();
        Livewire::test(UnfiledEmails::class)
            ->assertSee('third@gmail.com')
            // Each tab, clicked, opens its mailbox.
            ->assertSeeHtml('wire:click="$set(\'mailbox\', \'noreply\')"')
            ->assertSeeHtml('wire:click="$set(\'mailbox\', \'\')"')
            ->set('mailbox', 'noreply')->assertCanSeeTableRecords([$third])->assertCanNotSeeTableRecords([$email])
            ->set('mailbox', 'iflas')->assertCanSeeTableRecords([$email])->assertCanNotSeeTableRecords([$third])
            ->set('mailbox', '')->assertCanSeeTableRecords([$email, $third]);
        $tabs = Livewire::test(UnfiledEmails::class)->instance()->mailboxTabs();
        $this->assertSame([2, 1, 1], [$tabs['']['count'], $tabs['iflas']['count'], $tabs['noreply']['count']]);
        // Every mailbox of the list has its tab, with nothing waiting in it too.
        \App\Models\MailSender::create(['driver' => \App\Models\MailSender::MICROSOFT, 'address' => 'empty@jpa.ae', 'name' => 'Empty box', 'key' => 'empty', 'is_active' => true]);
        $this->assertSame(['label' => 'Empty box', 'count' => 0], Livewire::test(UnfiledEmails::class)->instance()->mailboxTabs()['empty']);
        $third->delete();

        // Filed by hand on any matter.
        Livewire::test(UnfiledEmails::class)->callTableAction('file', $email, ['matter_id' => $this->second->id]);
        $this->assertSame([$this->second->id, MatterEmail::FILED], [$email->refresh()->matter_id, $email->status]);

        // Switched off: a stranger's is left in the mailbox again.
        \App\Models\Setting::set(MatterReplyCollector::KEEP_ALL, false, 'mail');
        $this->collect($this->email(['from' => 'someone@gmail.com', 'message_id' => '<later@gmail.com>']));
        $this->assertSame(1, MatterEmail::count());
    }

    public function test_a_sender_on_the_ignore_list_is_never_read_again(): void
    {
        \App\Models\Setting::set(MatterReplyCollector::KEEP_ALL, true, 'mail');
        $marked = 0;
        $read = function () use (&$marked): void {
            $marked++;
        };

        // Two from a stranger wait on the Unfiled page; one from their colleague too.
        $this->collect(
            $this->email(['from' => 'News@Shop.com', 'from_name' => 'Shop']),
            $this->email(['from' => 'news@shop.com']),
            $this->email(['from' => 'sales@shop.com']),
        );
        $this->assertSame(3, MatterEmail::pending()->count());

        // "Ignore sender": theirs set aside, the colleague's left.
        $first = MatterEmail::query()->where('from', 'like', '%News@Shop.com%')->firstOrFail();
        Livewire::test(UnfiledEmails::class)->callTableAction('ignore', $first);
        $this->assertSame(['news@shop.com'], MatterReplyCollector::ignoredSenders());
        $this->assertSame(['sales@shop.com'], MatterEmail::pending()->pluck('from')->map(fn ($from) => MatterEmail::addressOf($from))->all());

        // New ones from them are not read at all — not kept, not marked read — even a reply or a party's.
        $this->assertSame(0, $this->collect($this->email(['from' => 'NEWS@shop.com', 'mark_read' => $read])));
        $this->lawyer->update(['email' => ['news@shop.com']]);
        $this->assertSame(0, $this->collect($this->email(['from' => 'news@shop.com', 'subject' => '986/2026', 'mark_read' => $read])));
        $this->assertSame([0, 3], [$marked, MatterEmail::count()]);

        // A whole domain.
        \App\Models\Setting::set(MatterReplyCollector::IGNORED, ['@shop.com'], 'mail');
        $this->assertTrue(MatterReplyCollector::isIgnored('anyone@shop.com'));
        $this->assertFalse(MatterReplyCollector::isIgnored('anyone@workshop.com'));
        $this->assertSame('a@b.com', MatterEmail::addressOf('Name <A@b.com>'));
    }

    public function test_names_are_compared_however_the_arabic_is_written(): void
    {
        $this->assertSame(
            IncomingEmailMatcher::normal('مؤسسة  إبراهيم للتجارة'),
            IncomingEmailMatcher::normal('مؤسسه ابراهيم لِلتجارة'),
        );
    }
}
