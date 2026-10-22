<?php

namespace Tests\Feature;

use App\Enums\NotificationType as Type;
use App\Enums\ProgressType;
use App\Filament\Mms\Resources\BulkMailCampaigns\Pages\ViewBulkMailCampaign;
use App\Filament\Mms\Resources\BulkMailCampaigns\RelationManagers\RecipientsRelationManager;
use App\Filament\Mms\Resources\Matters\Pages\ViewMatter;
use App\Filament\Mms\Resources\Matters\RelationManagers\EmailsRelationManager;
use App\Filament\Mms\Resources\Matters\RelationManagers\ProgressRelationManager;
use App\Filament\Support\EmailSendFields;
use App\Livewire\TabbedDatabaseNotifications;
use App\Models\BulkMailCampaign;
use App\Models\Letterhead;
use App\Models\LetterTemplate;
use App\Models\MailSender;
use App\Models\Matter;
use App\Models\MatterEmail;
use App\Models\MatterOneDriveFolder;
use App\Models\MatterParty;
use App\Models\Party;
use App\Models\Setting;
use App\Models\User;
use App\Services\MMS\Letters\LetterIssuer;
use App\Services\MMS\Letters\LetterMailer;
use App\Services\MMS\MatterOneDriveExplorer;
use App\Services\MMS\MatterOneDriveFolders;
use App\Services\MMS\MatterReplyCollector;
use App\Services\MMS\SenderMailer;
use App\Services\MMS\SentEmailArchive;
use App\Services\MMS\SentFolder;
use App\Services\Notifications\NotificationPreferences as Prefs;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use Livewire\Livewire;
use Mockery;
use Spatie\Permission\Models\Role;
use Tests\Support\MailSenders;
use Tests\TestCase;
use Webklex\PHPIMAP\Folder;
use Webklex\PHPIMAP\Message;
use Webklex\PHPIMAP\Query\WhereQuery;
use Webklex\PHPIMAP\Support\MessageCollection;

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
        MailSenders::add('iflas', ['username' => 'iflas@jpa.ae', 'address' => 'iflas@jpa.ae', 'name' => 'JPA', 'password' => 'x', 'host' => 'mail.test', 'port' => 587, 'encryption' => 'tls']);
        config([
            'mail.mailers.smtp.transport' => 'array',
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

            // A message looked for by its Message-ID, in every folder.
            if (preg_match("~/messages\?.*internetMessageId eq '([^']+)'~", $url, $wanted)) {
                return Http::response(['value' => array_values(array_filter($this->inbox, fn (array $item): bool => ($item['internetMessageId'] ?? null) === $wanted[1]))]);
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

    public function test_the_sender_is_told_of_a_reply_only_when_they_want_the_bell(): void
    {
        $sent = $this->sentEmail();
        Prefs::make()->saveUser($this->user, [Type::MATTER_REPLY_RECEIVED->value => ['bell' => false]]);

        $this->assertSame(1, app(MatterReplyCollector::class)->handle([$this->reply(['references' => ['<abc123@jpa.ae>']])], collect([$sent])));
        $this->assertSame(0, $this->user->notifications()->count());

        // Mandatory for the role: told whatever they chose, and the notification knows what it is.
        $role = Role::create(['name' => 'sender', 'guard_name' => 'web']);
        Prefs::make()->saveRole($role, [Type::MATTER_REPLY_RECEIVED->value => ['bell' => Prefs::LOCKED]]);
        $this->user->assignRole($role);

        $again = $this->reply(['message_id' => '<reply-2@dubaipolice.gov.ae>', 'references' => ['<abc123@jpa.ae>']]);
        $this->assertSame(1, app(MatterReplyCollector::class)->handle([$again], collect([$sent->fresh()])));
        $told = $this->user->refresh()->notifications()->sole();
        $this->assertSame(Type::MATTER_REPLY_RECEIVED->value, $told->data['viewData'][Type::DATA_KEY]);
    }

    public function test_a_reply_filed_on_its_matter_is_told_to_the_matters_people_too_in_their_emails_tab(): void
    {
        $sent = $this->sentEmail();
        $assistant = User::factory()->create();
        MatterParty::create(['matter_id' => $this->matter->id, 'role' => 'expert', 'type' => 'assistant',
            'party_id' => Party::factory()->assistant()->create(['user_id' => $assistant->id])->id]);
        $admin = User::factory()->create()->assignRole(Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']));

        app(MatterReplyCollector::class)->handle([$this->reply(['references' => ['<abc123@jpa.ae>']])], collect([$sent]));

        // The sender, the matter's assistant and the super admins: each once, about emails.
        foreach ([$this->user, $assistant, $admin] as $user) {
            $this->assertSame(1, TabbedDatabaseNotifications::onlyEmails($user->notifications())->count());
        }
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

        // On the matter: filed, listed in its Emails tab with what of it is in OneDrive.
        $this->assertSame(MatterEmail::FILED, $reply->status);
        $this->assertSame(['pdf' => true, 'eml' => false, 'attachments' => 1], collect($reply->onedrive)->except('folder')->all());
        // Where it was saved, on its record.
        $this->assertSame('المراسلات الواردة', $reply->onedrive['folder']);
        Livewire::test(EmailsRelationManager::class, [
            'ownerRecord' => $this->matter,
            'pageClass' => ViewMatter::class,
        ])
            ->assertCanSeeTableRecords([$reply, $sent])
            ->assertTableColumnStateSet('pdf_saved', 'Saved', $reply)
            ->assertTableColumnStateSet('attachments_saved', 'Saved', $reply);

        // Its PDF with the matter and in the OneDrive replies folder; its file in OneDrive only.
        $files = $this->matter->attachments()->where('type', 'correspondence')->pluck('name')->all();
        $name = SentEmailArchive::emailName(false, 'القيادة العامة لشرطة دبي', 'A different subject', Carbon::parse($reply->at));
        $this->assertSame([$name.'.pdf'], $files);
        $this->assertSame([$name.'.pdf', 'كشف.xlsx'], array_column($reply->files, 'name'));
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

    public function test_a_file_a_mail_program_marked_inline_is_kept_and_only_a_picture_in_the_text_is_not(): void
    {
        $pdf = chunk_split(base64_encode('%PDF-1.4 test'));
        $png = chunk_split(base64_encode("\x89PNG fake"));
        $mime = fn (string $parts) => "From: a@x.ae\r\nTo: b@y.ae\r\nSubject: s\r\nMessage-ID: <1@x>\r\nMIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"B\"\r\n\r\n--B\r\nContent-Type: text/html; charset=utf-8\r\n\r\n<p>hi</p><img src=\"cid:logo@x\">\r\n{$parts}--B--\r\n";

        $kept = function (string $parts) use ($mime): array {
            $message = Message::fromString($mime($parts));
            $html = (string) $message->getHTMLBody();
            $names = [];

            foreach ($message->getAttachments() as $a) {
                if ($a->disposition === 'inline' && MatterReplyCollector::isShownInText((string) $a->content_type, (string) $a->id, $html)) {
                    continue;
                }
                $names[] = $a->name ?: $a->filename;
            }

            return $names;
        };

        // A PDF as Apple Mail / Outlook for Mac sends it: inline, with a Content-ID — a file.
        $this->assertSame(['b.pdf'], $kept("--B\r\nContent-Type: application/pdf; name=\"b.pdf\"\r\nContent-Disposition: inline; filename=\"b.pdf\"\r\nContent-ID: <abc@mac>\r\nContent-Transfer-Encoding: base64\r\n\r\n{$pdf}\r\n"));
        // A normal attachment, one with no disposition, and a forwarded email: files.
        $this->assertSame(['a.pdf'], $kept("--B\r\nContent-Type: application/pdf; name=\"a.pdf\"\r\nContent-Disposition: attachment; filename=\"a.pdf\"\r\nContent-Transfer-Encoding: base64\r\n\r\n{$pdf}\r\n"));
        $this->assertSame(['c.pdf'], $kept("--B\r\nContent-Type: application/pdf; name=\"c.pdf\"\r\nContent-Transfer-Encoding: base64\r\n\r\n{$pdf}\r\n"));
        // The logo the text shows: not a file; a picture the text does not show: one.
        $logo = "--B\r\nContent-Type: image/png; name=\"logo.png\"\r\nContent-Disposition: inline; filename=\"logo.png\"\r\nContent-ID: <logo@x>\r\nContent-Transfer-Encoding: base64\r\n\r\n{$png}\r\n";
        $this->assertSame([], $kept($logo));
        $this->assertSame(['photo.png'], $kept(str_replace(['logo.png', '<logo@x>'], ['photo.png', '<other@x>'], $logo)));

        // The rule itself.
        $this->assertTrue(MatterReplyCollector::isShownInText('image/png', '<logo@x>', '<img src="cid:logo@x">'));
        $this->assertFalse(MatterReplyCollector::isShownInText('application/pdf', 'abc@mac', 'cid:abc@mac'));
        $this->assertTrue(MatterReplyCollector::isShownInText('image/png', '', '<p>no pictures</p>'));
        $this->assertFalse(MatterReplyCollector::isShownInText('image/png', '<other@x>', '<img src="cid:logo@x">'));
    }

    public function test_a_run_out_of_time_stops_before_the_next_message_and_says_how_far_it_got(): void
    {
        $sent = $this->sentEmail();
        $first = $this->reply(['at' => now()->subMinutes(30)->toDateTimeString()]);
        $second = $this->reply(['message_id' => '<reply-2@dubaipolice.gov.ae>', 'at' => now()->subMinutes(5)->toDateTimeString()]);
        $collector = app(MatterReplyCollector::class);

        // No time left: still one message — every run moves forward — then it stops, saying how far.
        $this->assertSame(1, $collector->handle([$first, $second], collect([$sent]), 'iflas', null, microtime(true) - 1, $progress));
        $this->assertTrue($progress['stopped']);
        $this->assertSame(Carbon::parse($first['at'])->toDateTimeString(), $progress['reached']->toDateTimeString());

        // Time enough: the rest, and how far — the newer one.
        $this->assertSame(1, $collector->handle([$first, $second], collect([$sent]), 'iflas', null, microtime(true) + 60, $progress));
        $this->assertFalse($progress['stopped']);
        $this->assertSame(Carbon::parse($second['at'])->toDateTimeString(), $progress['reached']->toDateTimeString());

        // Out of time while only reading again what the last run had read: it goes on until past it.
        $third = $this->reply(['message_id' => '<reply-3@dubaipolice.gov.ae>', 'at' => now()->subMinute()->toDateTimeString()]);
        $this->assertSame(1, $collector->handle([$first, $second, $third], collect([$sent]), 'iflas', null, microtime(true) - 1, $progress, Carbon::parse($second['at'])));
        $this->assertFalse($progress['stopped']);

        // A Date header in the future does not move how far it got.
        $future = $this->reply(['message_id' => '<reply-4@dubaipolice.gov.ae>', 'at' => now()->addDays(3)->toDateTimeString()]);
        $collector->handle([$future], collect([$sent]), 'iflas', null, null, $progress);
        $this->assertNull($progress['reached']);
    }

    public function test_an_email_cut_off_half_way_is_kept_again_not_skipped_for_ever(): void
    {
        $sent = $this->sentEmail();
        // Its row made, its files never kept — a moment ago: another run may still be keeping it, so it is left.
        $half = MatterEmail::create(['matter_id' => $this->matter->id, 'direction' => MatterEmail::RECEIVED, 'sender_key' => 'iflas', 'message_id' => 'reply-1@dubaipolice.gov.ae', 'subject' => 'RE', 'at' => now(), 'status' => MatterEmail::FILED]);
        $this->assertSame(0, app(MatterReplyCollector::class)->handle([$this->reply()], collect([$sent]), 'iflas'));
        $this->assertNull($half->fresh()->files);

        // Abandoned (older than any run lasts): kept again. A run for one matter never does this.
        $half->forceFill(['created_at' => now()->subMinutes(30)])->saveQuietly();
        $this->assertSame(0, app(MatterReplyCollector::class)->handle([$this->reply()], collect([$sent]), 'iflas', $this->matter->id));
        $this->assertSame(1, app(MatterReplyCollector::class)->handle([$this->reply()], collect([$sent]), 'iflas'));

        $reply = MatterEmail::query()->where('direction', MatterEmail::RECEIVED)->sole();
        $this->assertNotNull($reply->files);
        $this->assertSame(ProgressType::REPLY_RECEIVED, $this->matter->progress()->sole()->type);

        // Kept in full: read again, not kept twice.
        $this->assertSame(0, app(MatterReplyCollector::class)->handle([$this->reply()], collect([$sent]), 'iflas'));

        // One that never gets kept (it kills PHP every time) is tried a few times, then left — not for ever.
        $stuck = fn () => MatterEmail::create(['matter_id' => $this->matter->id, 'direction' => MatterEmail::RECEIVED, 'sender_key' => 'iflas', 'message_id' => 'stuck@x', 'subject' => 'RE', 'at' => now(), 'status' => MatterEmail::FILED])
            ->forceFill(['created_at' => now()->subHour()])->saveQuietly();
        Cache::put('mail-keep-retries:stuck@x', 3, now()->addDay());
        $stuck();
        $this->assertSame(0, app(MatterReplyCollector::class)->handle([$this->reply(['message_id' => '<stuck@x>'])], collect([$sent]), 'iflas'));
        $this->assertSame([], MatterEmail::where('message_id', 'stuck@x')->sole()->files);
    }

    public function test_a_reply_dated_in_another_time_zone_is_still_after_the_email_it_answers(): void
    {
        // Sent ten minutes ago (the app's time, +04); the reply stamped in UTC a minute ago.
        $sent = $this->sentEmail(['at' => now()->subMinutes(10)]);
        $reply = $this->reply(['references' => [], 'at' => now()->subMinute()->utc()->format('Y-m-d\TH:i:sP')]);

        $this->assertSame($sent->id, MatterReplyCollector::answers($reply, collect([$sent]))?->id);
    }

    public function test_the_automatic_run_is_due_ten_minutes_after_the_last_or_at_once_with_a_backlog(): void
    {
        Setting::forget(MatterReplyCollector::LAST_RUN);
        $this->assertTrue(MatterReplyCollector::isDue());

        $last = fn (array $run) => Setting::set(MatterReplyCollector::LAST_RUN, $run, 'mail', 'json');

        $last(['started_at' => now()->subMinutes(3)->toDateTimeString(), 'finished_at' => now()->subMinutes(3)->toDateTimeString(), 'stopped' => false]);
        $this->assertFalse(MatterReplyCollector::isDue());

        $last(['started_at' => now()->subMinutes(11)->toDateTimeString(), 'finished_at' => now()->subMinutes(11)->toDateTimeString(), 'stopped' => false]);
        $this->assertTrue(MatterReplyCollector::isDue());

        // Stopped for time: again at once.
        $last(['started_at' => now()->subMinute()->toDateTimeString(), 'finished_at' => now()->toDateTimeString(), 'stopped' => true]);
        $this->assertTrue(MatterReplyCollector::isDue());

        // Cut off (never finished) a minute ago: not yet — even after a run that had stopped for time.
        $last(['started_at' => now()->subMinute()->toDateTimeString(), 'finished_at' => null]);
        $this->assertFalse(MatterReplyCollector::isDue());
        $last(['started_at' => now()->subMinute()->toDateTimeString(), 'finished_at' => null, 'stopped' => true]);
        $this->assertFalse(MatterReplyCollector::isDue());
        $this->assertStringContainsString('has not finished', MatterReplyCollector::lastRunSummary());
    }

    public function test_a_cpanel_inbox_is_read_a_page_at_a_time_and_only_as_far_back_as_set(): void
    {
        MailSender::query()->update(['keep_all_inbox' => true]);
        // From a sender on the ignore list: read, then passed over — only the reading is looked at here.
        Setting::set(MatterReplyCollector::IGNORED, ['news@shop.com'], 'mail');
        $raw = fn (int $n, string $date) => "Message-ID: <m{$n}@shop.com>\r\nDate: {$date}\r\nFrom: news@shop.com\r\nTo: iflas@jpa.ae\r\nSubject: offer {$n}\r\nContent-Type: text/plain\r\n\r\nx";
        $message = function (int $n, string $date) use ($raw): Message {
            $message = Message::fromString($raw($n, $date));
            $message->uid = $n;

            return $message;
        };
        $recent = now()->subHour()->toRfc2822String();
        $pages = [
            1 => [$message(1, now()->subDays(3)->toRfc2822String()), ...array_map(fn ($n) => $message($n, $recent), range(2, 50))],
            2 => [$message(51, $recent), $message(52, $recent)],
        ];

        $asked = [];
        $run = function (?int $seconds) use ($pages, &$asked) {
            $asked = [];
            $query = Mockery::mock(WhereQuery::class);
            $query->shouldReceive('whereSince', 'leaveUnread', 'setFetchBody', 'setFetchFlags', 'setFetchOrderAsc')->andReturnSelf();
            $query->shouldReceive('errors')->andReturn([]);
            $query->shouldReceive('limit')->andReturnUsing(function (int $limit, int $page) use ($query, &$asked) {
                $asked[] = [$limit, $page];

                return $query;
            });
            $query->shouldReceive('get')->andReturnUsing(function () use ($pages, &$asked) {
                return new MessageCollection($pages[end($asked)[1]] ?? []);
            });
            $folder = Mockery::mock(Folder::class);
            $folder->shouldReceive('query')->andReturn($query);
            $mailboxes = Mockery::mock(SentFolder::class);
            $mailboxes->shouldReceive('inbox')->andReturn($folder);
            $this->app->instance(SentFolder::class, $mailboxes);
            Setting::forget(MatterReplyCollector::checkedKey('iflas'));

            return app(MatterReplyCollector::class)->collect(null, $seconds);
        };

        // With time: page after page of 50, until a short one.
        $this->assertSame([], $run(60)['errors']);
        $this->assertSame([[50, 1], [50, 2]], $asked);

        // Out of time: the page it is on, not the next one.
        $run(0);
        $this->assertSame([[50, 1]], $asked);

        // The days set, within the most allowed.
        Setting::set(MatterReplyCollector::LOOK_BACK, 7, 'mail');
        $this->assertSame(7, MatterReplyCollector::lookBackDays());
        Setting::set(MatterReplyCollector::LOOK_BACK, 99, 'mail');
        $this->assertSame(MatterReplyCollector::MAX_LOOK_BACK, MatterReplyCollector::lookBackDays());
    }

    public function test_set_to_keep_every_email_a_mailbox_never_sent_from_is_read_too(): void
    {
        MailSender::create(['driver' => MailSender::MICROSOFT, 'address' => 'info@jpa.ae', 'name' => 'Info', 'key' => 'info', 'is_active' => true]);
        $this->inbox = [[
            'id' => 'AAMk9', 'subject' => 'استفسار', 'internetMessageId' => '<q@gmail.com>', 'hasAttachments' => false,
            'from' => ['emailAddress' => ['address' => 'someone@gmail.com', 'name' => 'Someone']],
            'toRecipients' => [['emailAddress' => ['address' => 'info@jpa.ae']]], 'receivedDateTime' => now()->subMinute()->toIso8601String(),
            'body' => ['contentType' => 'html', 'content' => '<p>؟</p>'], 'internetMessageHeaders' => [], 'isRead' => false,
        ]];

        // Only replies and parties': a mailbox nothing was sent from is not read.
        app(MatterReplyCollector::class)->collect(null, 60);
        Http::assertNotSent(fn (Request $request) => str_contains(rawurldecode($request->url()), 'users/info@jpa.ae/mailFolders'));

        // That mailbox set to keep every email: it is.
        MailSender::query()->where('key', 'info')->first()->update(['keep_all_inbox' => true]);
        app(MatterReplyCollector::class)->collect(null, 60);
        $this->assertSame('info', MatterEmail::pending()->sole()->sender_key);
        // Unread in the mailbox before: kept, so an undo can put it back.
        $this->assertTrue(MatterEmail::pending()->sole()->was_unread);
        // Read for the last day only, never further back.
        $since = now()->subDay()->utc()->format('Y-m-d\TH');
        Http::assertSent(fn (Request $request) => str_contains(rawurldecode($request->url()), 'users/info@jpa.ae/mailFolders')
            && str_contains(rawurldecode($request->url()), 'receivedDateTime ge '.$since));
    }

    public function test_an_automatic_run_records_itself_and_goes_on_where_a_short_one_stopped(): void
    {
        MailSender::create(['driver' => MailSender::MICROSOFT, 'address' => 'no_reply@jpa.ae', 'name' => 'JPA', 'key' => 'noreply', 'is_active' => true]);
        $this->sentEmail(['sender_key' => 'noreply']);
        $message = fn (string $id, string $at) => [
            'id' => $id, 'subject' => 'RE: طلب بيانات — JPA/2026/986/1', 'internetMessageId' => '<'.$id.'@police>', 'hasAttachments' => false,
            'from' => ['emailAddress' => ['address' => 'info@dubaipolice.gov.ae', 'name' => 'شرطة دبي']],
            'toRecipients' => [['emailAddress' => ['address' => 'no_reply@jpa.ae']]], 'receivedDateTime' => $at,
            'body' => ['contentType' => 'html', 'content' => '<p>تم.</p>'], 'internetMessageHeaders' => [],
        ];
        $older = now()->subMinutes(20);
        $this->inbox = [$message('r1', $older->toIso8601String()), $message('r2', now()->subMinute()->toIso8601String())];
        $collector = app(MatterReplyCollector::class);

        // No time at all: one reply still kept, then it stops — the mailbox read up to that one, due again at once.
        $result = $collector->collect(null, 0);
        $this->assertTrue($result['stopped']);
        $this->assertSame(1, $result['replies']);
        $this->assertTrue(MatterReplyCollector::isDue());
        $this->assertSame($older->copy()->setTimezone(config('app.timezone'))->toDateTimeString(), Setting::get('mail_inbox_checked_at.noreply'));
        $this->assertStringContainsString('more to read', MatterReplyCollector::lastRunSummary());

        // With time: it goes on from there — the other reply — and is not due again yet.
        $result = $collector->collect(null, 60);
        $this->assertFalse($result['stopped']);
        $this->assertSame(1, $result['replies']);
        $this->assertFalse(MatterReplyCollector::isDue());
        $this->assertStringContainsString('1 email kept', MatterReplyCollector::lastRunSummary());

        // The inbox is read oldest first, so a run that stops can go on from there.
        Http::assertSent(fn (Request $request) => str_contains(rawurldecode($request->url()), '$orderby=receivedDateTime asc'));

        // An error is shown, and the command says it failed.
        $this->mailReadAllowed = false;
        Setting::forget(MatterReplyCollector::LAST_RUN);
        $this->artisan('mail:collect-replies', ['--max-seconds' => 30])->assertFailed();
        $this->assertStringContainsString('Mail.Read', MatterReplyCollector::lastRunSummary());
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

    public function test_a_reply_to_a_bulk_email_of_no_matter_is_kept_with_its_recipient(): void
    {
        $campaign = BulkMailCampaign::create(['name' => 'Notice', 'subject' => 'إخطار', 'body' => '<p>…</p>', 'from_sender_key' => 'iflas', 'created_by' => $this->user->id, 'status' => 'completed']);
        $recipient = $campaign->recipients()->create(['email' => 'client@x.ae', 'name' => 'Client', 'status' => 'sent', 'sent_at' => now()->subDay()]);

        // As the bulk sender remembers it: by its recipient, no matter.
        MatterEmail::recordSent(null, $recipient, 'iflas', 'bulk-1@jpa.ae', 'إخطار', ['client@x.ae'], $this->user->id);
        $sent = MatterEmail::sole();
        $this->assertNull($sent->matter_id);

        $this->assertSame(1, app(MatterReplyCollector::class)->handle([$this->reply([
            'message_id' => '<r-bulk@x.ae>', 'references' => ['<bulk-1@jpa.ae>'], 'subject' => 'RE: إخطار', 'from' => 'client@x.ae', 'from_name' => 'Client',
        ])], collect([$sent])));

        // Its PDF and file kept with it — no matter, nothing in OneDrive, no progress.
        $reply = MatterEmail::query()->where('direction', MatterEmail::RECEIVED)->sole();
        $this->assertNull($reply->matter_id);
        $this->assertCount(2, $reply->files);
        $this->assertStringEndsWith('.pdf', $reply->files[0]['name']);
        $this->assertSame('كشف.xlsx', $reply->files[1]['name']);
        $this->assertSame('xlsx-bytes', Storage::disk('public')->get($reply->files[1]['path']));
        $this->assertSame([], $this->uploads);
        $this->assertSame(1, $this->user->notifications()->count());

        // On the campaign's recipients: the reply, and a way to open it.
        Livewire::test(RecipientsRelationManager::class, ['ownerRecord' => $campaign, 'pageClass' => ViewBulkMailCampaign::class])
            ->assertTableColumnStateSet('reply', $reply->at, $recipient)
            ->assertTableActionVisible('replies', $recipient)
            ->mountTableAction('replies', $recipient)
            ->assertMountedActionModalSee('كشف.xlsx');
    }

    public function test_letters_minutes_and_bulk_mail_start_from_the_default_mailbox(): void
    {
        MailSenders::add('legal', ['username' => 'legal@jpa.ae', 'address' => 'legal@jpa.ae', 'name' => 'Legal', 'password' => 'x', 'host' => 'mail.test', 'port' => 587, 'encryption' => 'tls']);
        Setting::set(EmailSendFields::DEFAULT_SENDER, 'legal', 'mail');

        $this->assertSame('legal', EmailSendFields::defaultSender());

        // One no longer there: the next choice.
        Setting::set(EmailSendFields::DEFAULT_SENDER, 'gone', 'mail');
        $this->assertSame(array_key_first(SenderMailer::options()), EmailSendFields::defaultSender());
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
            ->assertNotified(__('Checking for emails'));
        // Read on the queue: what came of it in the notifications of whoever asked.
        $this->assertContains(trans_choice('No new replies.|:count new reply kept.|:count new replies kept.', 1, ['count' => 1]), auth()->user()->notifications()->get()->pluck('data.title')->all());

        // The file it came with — in OneDrive only, not a signature's inline picture.
        $reply = MatterEmail::query()->where('direction', MatterEmail::RECEIVED)->sole();
        $this->assertSame(['شهادة.pdf'], array_column($reply->attachmentFiles(), 'name'));
        $this->assertTrue(collect($this->uploads)->contains(fn (string $url) => str_contains($url, 'شهادة.pdf')));
        $this->assertFalse(collect($this->uploads)->contains(fn (string $url) => str_contains($url, 'logo.png')));
        $this->assertSame(0, $this->matter->attachments()->whereIn('name', ['شهادة.pdf', 'logo.png'])->count());

        $this->mailReadAllowed = false;
        MatterEmail::query()->where('direction', MatterEmail::RECEIVED)->delete();
        $result = app(MatterReplyCollector::class)->collect($this->matter->id);
        $this->assertSame(0, $result['replies']);
        $this->assertStringContainsString('Mail.Read', $result['errors'][0]);
    }

    public function test_an_email_is_made_again_from_its_mailbox_each_file_in_its_place(): void
    {
        MailSender::create(['driver' => MailSender::MICROSOFT, 'address' => 'no_reply@jpa.ae', 'name' => 'JPA', 'key' => 'noreply', 'is_active' => true]);
        $this->sentEmail(['sender_key' => 'noreply', 'message_id' => 'graph-made-its-own@jpa.ae']);
        $this->inbox = [[
            'id' => 'AAMk1', 'subject' => 'RE: طلب بيانات — JPA/2026/986/1', 'internetMessageId' => '<r@police>', 'hasAttachments' => true,
            'from' => ['emailAddress' => ['address' => 'info@dubaipolice.gov.ae', 'name' => 'شرطة دبي']],
            'toRecipients' => [['emailAddress' => ['address' => 'no_reply@jpa.ae']]], 'receivedDateTime' => now()->toIso8601String(),
            // As Outlook.com sends Arabic: its HTML still naming the charset it came in.
            'body' => ['contentType' => 'html', 'content' => '<html><head><meta http-equiv="Content-Type" content="text/html; charset=windows-1256"></head><body><p>تم.</p></body></html>'],
            'internetMessageHeaders' => [],
        ]];
        app(MatterReplyCollector::class)->collect($this->matter->id);
        $email = MatterEmail::query()->where('direction', MatterEmail::RECEIVED)->sole();
        $pdfPath = $email->files[0]['path'];
        Storage::disk('public')->put($pdfPath, 'the PDF made wrongly');
        // A file it came with that OneDrive refused before, kept in the system under a name read wrongly.
        $garbled = 'attachments/emails/'.$this->matter->id.'/garbled.pdf';
        Storage::disk('public')->put($garbled, '%PDF-garbled');
        $this->matter->attachments()->create(['user_id' => $this->user->id, 'type' => 'correspondence', 'path' => $garbled, 'name' => "ورّrH7q\u{0019}.pdf", 'size' => 12, 'extension' => 'pdf']);
        $email->update(['files' => [...$email->files, ['name' => "ورّrH7q\u{0019}.pdf", 'path' => $garbled]]]);
        $this->uploads = [];

        Livewire::test(EmailsRelationManager::class, ['ownerRecord' => $this->matter, 'pageClass' => ViewMatter::class])
            ->callTableAction('regenerate', $email)
            ->assertNotified(__('Made again'));

        // The wrongly named copy gone; the file in OneDrive under its name.
        Storage::disk('public')->assertMissing($garbled);
        $this->assertSame(0, $this->matter->attachments()->withTrashed()->where('path', $garbled)->count());

        // The PDF made anew in its place — the same file the matter's attachments link to.
        $email->refresh();
        $this->assertSame($pdfPath, $email->files[0]['path']);
        $this->assertStringStartsWith('%PDF', Storage::disk('public')->get($pdfPath));
        $this->assertSame(1, $this->matter->attachments()->where('path', $pdfPath)->count());
        // OneDrive's copies — the PDF, the original, the file it came with — replaced, not put beside them.
        $this->assertCount(3, $this->uploads);
        $this->assertTrue(collect($this->uploads)->every(fn (string $url): bool => str_contains($url, 'conflictBehavior=replace')));
        $this->assertTrue(collect($this->uploads)->contains(fn (string $url): bool => str_contains($url, 'شهادة.pdf')));
        $this->assertSame(['pdf' => true, 'eml' => true, 'attachments' => 1], array_intersect_key($email->onedrive, array_flip(['pdf', 'eml', 'attachments'])));

        // Gone from the mailbox: said so.
        $this->inbox = [];
        Livewire::test(EmailsRelationManager::class, ['ownerRecord' => $this->matter, 'pageClass' => ViewMatter::class])
            ->callTableAction('regenerate', $email)
            ->assertNotified(__('The email could not be made again'));
    }

    public function test_a_matter_with_no_onedrive_folder_is_named_in_the_action_as_a_bullet_list(): void
    {
        Setting::set(MatterOneDriveFolders::ENABLED, true, 'onedrive');
        MailSender::create(['driver' => MailSender::MICROSOFT, 'address' => 'no_reply@jpa.ae', 'name' => 'JPA', 'key' => 'noreply', 'is_active' => true]);
        $this->sentEmail(['sender_key' => 'noreply', 'message_id' => 'graph-made-its-own@jpa.ae']);
        $this->inbox = [[
            'id' => 'AAMk1', 'subject' => 'RE: طلب بيانات — JPA/2026/986/1', 'internetMessageId' => '<r@police>', 'hasAttachments' => false,
            'from' => ['emailAddress' => ['address' => 'info@dubaipolice.gov.ae', 'name' => 'شرطة دبي']],
            'toRecipients' => [['emailAddress' => ['address' => 'no_reply@jpa.ae']]], 'receivedDateTime' => now()->toIso8601String(),
            'body' => ['contentType' => 'html', 'content' => '<p>تم.</p>'], 'internetMessageHeaders' => [],
        ]];

        // Linked to its folder: nothing to say.
        $this->assertTrue(MatterOneDriveFolders::folderFor($this->matter) !== null);
        $this->assertFalse(MatterOneDriveFolders::receivedEmailsNotInOneDrive($this->matter));

        // Not linked: kept in the system, and said — the matter named, with the way to it.
        MatterOneDriveFolder::query()->delete();
        $this->assertTrue(MatterOneDriveFolders::receivedEmailsNotInOneDrive($this->matter->fresh()));

        $result = app(MatterReplyCollector::class)->collect($this->matter->id);
        $this->assertSame(1, $result['replies']);
        $this->assertSame(['2026/986'], array_column($result['unlinked'], 'label'));
        // The email to download and put in OneDrive by hand: its PDF, from where it is kept.
        $downloads = $result['unlinked'][0]['downloads'];
        // Its PDF only: the original email (.eml) goes to OneDrive, never into the system.
        $this->assertCount(1, $downloads);
        $this->assertStringEndsWith('.pdf', $downloads[0]['name']);
        // Opened through the attachment controller, which checks who may.
        $this->assertSame($this->matter->attachments()->where('type', 'correspondence')->sole()->url(), $downloads[0]['url']);
        $this->assertSame(1, $this->matter->attachments()->where('type', 'correspondence')->count());

        // In the action, as a bullet list.
        MatterEmail::query()->where('direction', MatterEmail::RECEIVED)->delete();
        $html = MatterReplyCollector::bullets(['One', new HtmlString('<b>Two</b>'), null, ''])->toHtml();
        $this->assertSame('<ul style="list-style: disc; padding-inline-start: 1.1rem; margin: 0;"><li>One</li><li><b>Two</b></li></ul>', $html);

        Livewire::test(ProgressRelationManager::class, ['ownerRecord' => $this->matter, 'pageClass' => ViewMatter::class])
            ->callTableAction('collectReplies')
            ->assertNotified(__('Checking for emails'));
        // Read on the queue: what came of it in the notifications of whoever asked.
        $this->assertContains(trans_choice('No new replies.|:count new reply kept.|:count new replies kept.', 1, ['count' => 1]), auth()->user()->notifications()->get()->pluck('data.title')->all());
    }
}
