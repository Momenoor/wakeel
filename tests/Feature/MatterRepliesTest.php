<?php

namespace Tests\Feature;

use App\Enums\ProgressType;
use App\Filament\Mms\Resources\Matters\Pages\ViewMatter;
use App\Filament\Mms\Resources\Matters\RelationManagers\ProgressRelationManager;
use App\Models\Letterhead;
use App\Models\LetterTemplate;
use App\Models\MailSender;
use App\Models\Matter;
use App\Models\MatterEmail;
use App\Models\MatterOneDriveFolder;
use App\Models\Party;
use App\Models\User;
use App\Services\MMS\Letters\LetterIssuer;
use App\Services\MMS\Letters\LetterMailer;
use App\Services\MMS\MatterOneDriveExplorer;
use App\Services\MMS\MatterReplyCollector;
use App\Services\MMS\SentEmailArchive;
use App\Services\MMS\SentFolder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Mockery;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Replies to a matter's emails, collected from the sending mailbox and kept
 * — as a PDF with every file they came with — on the matter, in its OneDrive
 * replies folder and in its progress.
 */
class MatterRepliesTest extends TestCase
{
    use RefreshDatabase;

    private Matter $matter;

    private User $user;

    /** @var list<string> OneDrive files put */
    private array $uploads = [];

    /** @var list<array<string, mixed>> what the Microsoft 365 inbox holds */
    private array $inbox = [];

    private bool $mailReadAllowed = true;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->withoutDefer();
        config([
            'mail.mailers.smtp.transport' => 'array',
            'mail_senders.senders.iflas' => ['username' => 'iflas@jpa.ae', 'address' => 'iflas@jpa.ae', 'name' => 'JPA', 'password' => 'x', 'host' => 'mail.test', 'port' => 587, 'encryption' => 'tls'],
            'mail.mailers.microsoft-graph' => ['transport' => 'microsoft-graph', 'tenant_id' => 'tenant', 'client_id' => 'client', 'client_secret' => 'secret'],
            'services.outlook' => ['tenant_id' => 'tenant', 'client_id' => 'client', 'client_secret' => 'secret'],
        ]);
        $this->app->instance(SentFolder::class, Mockery::spy(SentFolder::class));
        Gate::before(fn () => true);
        Filament::setCurrentPanel(Filament::getPanel('mms'));

        Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        $this->user = User::factory()->create()->assignRole('super-admin');
        $this->actingAs($this->user);

        $this->matter = Matter::factory()->create(['number' => '986', 'year' => '2026']);
        MatterOneDriveFolder::create([
            'matter_id' => $this->matter->id, 'folder_name' => '986-2026', 'status' => MatterOneDriveFolder::CREATED, 'drive_item_id' => 'root-id',
            'party_id' => Party::factory()->assistant()->create(['onedrive_email' => 'amr@firm.ae'])->id,
        ]);

        Http::fake(function (Request $request) {
            $url = rawurldecode($request->url());

            if (str_contains($url, 'login.microsoftonline.com')) {
                return Http::response(['access_token' => 'token']);
            }

            if (str_contains($url, '/mailFolders/Inbox/messages')) {
                return $this->mailReadAllowed
                    ? Http::response(['value' => $this->inbox])
                    : Http::response(['error' => ['code' => 'ErrorAccessDenied', 'message' => 'Access is denied.']], 403);
            }

            if (str_contains($url, '/attachments')) {
                return Http::response(['value' => [
                    ['name' => 'شهادة.pdf', 'contentType' => 'application/pdf', 'isInline' => false, 'contentBytes' => base64_encode('%PDF-certificate')],
                    ['name' => 'logo.png', 'contentType' => 'image/png', 'isInline' => true, 'contentBytes' => base64_encode('png')],
                ]]);
            }

            if ($request->method() === 'PUT') {
                $this->uploads[] = $url;
            }

            return Http::response(['id' => $request->method() === 'POST' ? 'replies-folder-id' : 'file-id', 'webUrl' => 'https://od/x'], 201);
        });
    }

    private function sentEmail(array $attributes = []): MatterEmail
    {
        return MatterEmail::create([
            'matter_id' => $this->matter->id, 'direction' => MatterEmail::SENT, 'sender_key' => 'iflas',
            'message_id' => 'abc123@jpa.ae', 'subject' => 'طلب بيانات — JPA/2026/986/1', 'to' => ['info@dubaipolice.gov.ae', 'clerk@court.ae'],
            'at' => now()->subDay(), 'user_id' => $this->user->id, ...$attributes,
        ]);
    }

    private function reply(array $attributes = []): array
    {
        return [
            'message_id' => '<reply-1@dubaipolice.gov.ae>', 'references' => [], 'subject' => 'RE: طلب بيانات — JPA/2026/986/1',
            'from' => 'info@dubaipolice.gov.ae', 'from_name' => 'شرطة دبي', 'to' => ['iflas@jpa.ae'], 'cc' => [], 'at' => now()->toDateTimeString(),
            'body' => '<p>مرفق المطلوب.</p>',
            'attachments' => [['name' => 'كشف.xlsx', 'contents' => 'xlsx-bytes', 'mime' => 'application/vnd.ms-excel']],
            ...$attributes,
        ];
    }

    public function test_each_email_sent_from_a_matter_is_remembered_for_its_replies(): void
    {
        Letterhead::create(['name' => 'Main', 'is_default' => true, 'elements' => Letterhead::defaultElements()]);
        $letter = app(LetterIssuer::class)->issue(
            LetterTemplate::create(['name' => 'n', 'slug' => 'n', 'locale' => 'ar', 'category' => 'letter', 'subject' => 'طلب بيانات', 'body' => '<p>x</p>']),
            $this->matter, [['name' => 'منى', 'role' => null, 'emails' => ['Mona@Example.com']]], [],
        );

        app(LetterMailer::class)->send($letter, 'iflas', cc: ['boss@jpa.ae']);

        $sent = MatterEmail::sole();
        $this->assertSame([MatterEmail::SENT, 'iflas', $this->user->id], [$sent->direction, $sent->sender_key, $sent->user_id]);
        $this->assertSame(['mona@example.com', 'boss@jpa.ae'], $sent->to);
        $this->assertNotNull($sent->message_id);
        $this->assertTrue($sent->source->is($letter));
    }

    public function test_a_reply_is_kept_with_its_files_in_onedrive_and_progress_and_the_sender_told(): void
    {
        $sent = $this->sentEmail();
        // Who replied, by their party's name.
        Party::factory()->create(['name' => 'القيادة العامة لشرطة دبي', 'email' => ['Info@DubaiPolice.gov.ae']]);
        $graph = [];
        Http::globalRequestMiddleware(function ($request) use (&$graph) {
            if ($request->getMethod() === 'POST' && str_contains((string) $request->getUri(), '/children')) {
                $graph[] = json_decode((string) $request->getBody(), true)['name'] ?? null;
            }

            return $request;
        });

        $kept = app(MatterReplyCollector::class)->handle([$this->reply(['references' => ['<abc123@jpa.ae>'], 'subject' => 'A different subject'])], collect([$sent]));
        $this->assertSame(1, $kept);

        $reply = MatterEmail::query()->where('direction', MatterEmail::RECEIVED)->sole();
        $this->assertSame($sent->id, $reply->parent_id);
        $this->assertSame('reply-1@dubaipolice.gov.ae', $reply->message_id);
        $this->assertSame('شرطة دبي <info@dubaipolice.gov.ae>', $reply->from);

        // Its PDF and its file, with the matter and in the OneDrive replies folder.
        $files = $this->matter->attachments()->where('type', 'correspondence')->pluck('name')->all();
        $this->assertCount(2, $files);
        $name = SentEmailArchive::emailName(false, 'القيادة العامة لشرطة دبي', 'A different subject', Carbon::parse($reply->at));
        $this->assertSame($name.'.pdf', $files[0]);
        $this->assertSame('كشف.xlsx', $files[1]);
        // The reply in the replies folder; its file in a folder of its name.
        $this->assertCount(2, $this->uploads);
        $this->assertStringEndsWith(':/'.$name.'.pdf:/content?@microsoft.graph.conflictBehavior=rename', $this->uploads[0]);
        $this->assertStringContainsString('/items/replies-folder-id:/كشف.xlsx', $this->uploads[1]);
        $this->assertSame(['المراسلات الواردة', 'المراسلات الواردة', MatterOneDriveExplorer::cleanName($name)], $graph);

        $step = $this->matter->progress()->sole();
        $this->assertSame(ProgressType::REPLY_RECEIVED, $step->type);
        $this->assertStringContainsString('طلب بيانات', $step->details);
        $this->assertSame(1, $this->user->notifications()->count());

        // Read again: kept once.
        $this->assertSame(0, app(MatterReplyCollector::class)->handle([$this->reply()], collect([$sent])));
    }

    public function test_without_its_headers_a_reply_is_known_by_its_subject_from_someone_it_went_to(): void
    {
        $sent = collect([$this->sentEmail()]);
        $answers = fn (array $overrides) => MatterReplyCollector::answers($this->reply($overrides), $sent);

        $this->assertNotNull($answers([]));
        $this->assertNotNull($answers(['subject' => 'رد: Fwd: طلب بيانات — JPA/2026/986/1', 'from' => 'Clerk@Court.ae']));
        // Someone it didn't go to; another subject; before it was sent.
        $this->assertNull($answers(['from' => 'stranger@x.ae']));
        $this->assertNull($answers(['subject' => 'RE: موضوع آخر']));
        $this->assertNull($answers(['at' => now()->subWeek()->toDateTimeString()]));

        $this->assertSame('طلب بيانات', MatterReplyCollector::bareSubject('RE: Fw: رد: طلب   بيانات'));
    }

    public function test_a_microsoft_365_inbox_is_read_and_a_refusal_says_what_to_grant(): void
    {
        MailSender::create(['driver' => MailSender::MICROSOFT, 'address' => 'no_reply@jpa.ae', 'name' => 'JPA', 'key' => 'noreply', 'is_active' => true]);
        $this->sentEmail(['sender_key' => 'noreply', 'message_id' => 'graph-made-its-own@jpa.ae']);
        $this->inbox = [[
            'id' => 'AAMk1', 'subject' => 'RE: طلب بيانات — JPA/2026/986/1', 'internetMessageId' => '<r@police>', 'hasAttachments' => true,
            'from' => ['emailAddress' => ['address' => 'info@dubaipolice.gov.ae', 'name' => 'شرطة دبي']],
            'toRecipients' => [['emailAddress' => ['address' => 'no_reply@jpa.ae']]], 'receivedDateTime' => now()->toIso8601String(),
            'body' => ['contentType' => 'html', 'content' => '<p>تم.</p>'], 'internetMessageHeaders' => [],
        ]];

        // From the matter's Progress tab: now, not at the next round.
        Livewire::test(ProgressRelationManager::class, ['ownerRecord' => $this->matter, 'pageClass' => ViewMatter::class])
            ->callTableAction('collectReplies')
            ->assertNotified(trans_choice('No new replies.|:count new reply kept.|:count new replies kept.', 1, ['count' => 1]));

        // The file it came with — not a signature's inline picture.
        $this->assertSame(1, $this->matter->attachments()->where('name', 'شهادة.pdf')->count());
        $this->assertSame(0, $this->matter->attachments()->where('name', 'logo.png')->count());

        $this->mailReadAllowed = false;
        MatterEmail::query()->where('direction', MatterEmail::RECEIVED)->delete();
        $result = app(MatterReplyCollector::class)->collect($this->matter->id);
        $this->assertSame(0, $result['replies']);
        $this->assertStringContainsString('Mail.Read', $result['errors'][0]);
    }
}
