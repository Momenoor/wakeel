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

        // Its PDF and its file on the matter, in OneDrive, a step in its progress.
        $this->assertSame(2, $this->second->attachments()->count());
        $this->assertStringContainsString(__('Incoming email'), $second->files[0]['name']);
        $this->assertContains('عقد.pdf', array_column($second->files, 'name'));
        $this->assertNotEmpty($this->uploads);
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
        $this->assertContains('عقد.pdf', $this->first->attachments()->pluck('name')->all());
        $this->assertSame(2, $this->first->attachments()->count());
        $this->assertSame(ProgressType::EMAIL_RECEIVED, $this->first->progress()->sole()->type);
        $this->assertCount(2, $this->uploads);
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

        // It names a matter not theirs: not sure — a person decides.
        $this->collect($this->email(['subject' => 'بخصوص 987/2026']));
        $this->assertSame(MatterEmail::PENDING, MatterEmail::query()->latest('id')->first()->status);
    }

    public function test_only_a_partys_emails_are_kept(): void
    {
        // A stranger; one of the office's experts; the office's own mailbox; one with no id.
        $expert = Party::factory()->create(['email' => ['expert@jpa.ae']]);
        MatterParty::create(['matter_id' => $this->first->id, 'party_id' => $expert->id, 'role' => 'expert', 'type' => 'certified']);

        $this->assertSame(0, $this->collect(
            $this->email(['from' => 'someone@gmail.com', 'subject' => '986/2026']),
            $this->email(['from' => 'expert@jpa.ae', 'subject' => '986/2026']),
            $this->email(['from' => 'iflas@jpa.ae', 'subject' => '986/2026']),
            $this->email(['message_id' => '', 'subject' => '986/2026']),
        ));
        $this->assertSame(0, MatterEmail::count());
    }

    public function test_names_are_compared_however_the_arabic_is_written(): void
    {
        $this->assertSame(
            IncomingEmailMatcher::normal('مؤسسة  إبراهيم للتجارة'),
            IncomingEmailMatcher::normal('مؤسسه ابراهيم لِلتجارة'),
        );
    }
}
