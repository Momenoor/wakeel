<?php

namespace Tests\Feature\Letters;

use App\Filament\Mms\Resources\Matters\Pages\ViewMatter;
use App\Filament\Mms\Resources\Matters\RelationManagers\LettersRelationManager;
use App\Filament\Support\EmailSendFields;
use App\Jobs\ShareAttachments;
use App\Models\Court;
use App\Models\EmailTemplate;
use App\Models\Letterhead;
use App\Models\LetterTemplate;
use App\Models\Matter;
use App\Models\MatterLetter;
use App\Models\MatterParty;
use App\Models\Party;
use App\Models\Setting;
use App\Models\Type;
use App\Models\User;
use App\Services\MMS\Letters\LetterIssuer;
use App\Services\MMS\Letters\LetterMailer;
use App\Services\MMS\Letters\SharedAttachments;
use App\Services\MMS\MatterOneDriveFolders;
use App\Services\MMS\SentFolder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Mockery;
use Spatie\Permission\Models\Role;
use Symfony\Component\Mime\Email;
use Tests\Support\FakeOneDrive;
use Tests\Support\MailSenders;
use Tests\TestCase;

/**
 * A letter's files too large to send by email: put in a folder of the matter's OneDrive folder and sent as a link.
 */
class SharedAttachmentsTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<Email> */
    private array $sent = [];

    private FakeOneDrive $od;

    private LetterTemplate $template;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->withoutDefer();

        MailSenders::add('iflas', [
            'username' => 'iflas@jpaemirates.com', 'address' => 'iflas@jpaemirates.com', 'name' => 'JPA Iflas',
            'password' => 'secret', 'host' => 'mail.example.com', 'port' => 587, 'encryption' => 'tls',
        ]);
        config([
            'mail.mailers.smtp.transport' => 'array',
            'mail.mailers.microsoft-graph' => ['transport' => 'microsoft-graph', 'tenant_id' => 'tenant', 'client_id' => 'client', 'client_secret' => 'secret'],
        ]);
        Event::listen(MessageSent::class, fn (MessageSent $event) => $this->sent[] = $event->message);
        $this->app->instance(SentFolder::class, Mockery::spy(SentFolder::class));
        $this->actingAs(User::factory()->create()->assignRole(Role::firstOrCreate(['name' => config('filament-shield.super_admin.name', 'super_admin'), 'guard_name' => 'web'])));
        Filament::setCurrentPanel('admin');

        // Over 1 MB, the files go by link.
        Setting::clearCache();
        Setting::set(SharedAttachments::LIMIT, 1, 'mail');
        Setting::set(MatterOneDriveFolders::ENABLED, true, 'onedrive');
        Setting::set(MatterOneDriveFolders::ENABLED_AT, now()->subMinute()->toDateTimeString(), 'onedrive');
        Setting::set(MatterOneDriveFolders::SUBFOLDERS, '01 المراسلات', 'onedrive');
        $this->od = (new FakeOneDrive)->install();

        $letterhead = Letterhead::create(['name' => 'Main', 'is_default' => true, 'elements' => Letterhead::defaultElements()]);
        $this->template = LetterTemplate::create([
            'name' => 'إشعار', 'slug' => 'notice', 'locale' => 'ar', 'category' => 'letter', 'letterhead_id' => $letterhead->id,
            'subject' => 'إشعار الدعوى رقم {{matter.reference}}',
            'body' => '<p>{{recipients}}</p><p>نص الخطاب.</p>',
        ]);
    }

    /** A matter with a folder in its assistant's OneDrive (or none), and a letter on it to منى. */
    private function letter(bool $withFolder = true): MatterLetter
    {
        $matter = Matter::factory()->create(['year' => 2026, 'number' => 123, 'type_id' => Type::firstOrCreate(['name' => 'خبرة'])->id, 'court_id' => Court::firstOrCreate(['name' => 'محاكم دبي'])->id]);

        if ($withFolder) {
            $amr = Party::factory()->assistant()->create(['name' => 'Amr', 'onedrive_email' => 'amr@firm.ae', 'onedrive_path' => 'Work/Matters']);
            MatterParty::create(['matter_id' => $matter->id, 'party_id' => $amr->id, 'role' => 'expert', 'type' => 'assistant']);
        }

        return app(LetterIssuer::class)->issue($this->template, $matter, [['name' => 'منى أحمد', 'role' => 'المدعي', 'emails' => ['mona@example.com']]], []);
    }

    /**
     * @return array{path: string, name: string}
     */
    private function file(string $name, int $bytes): array
    {
        Storage::disk('local')->put('letter-attachments/'.$name, str_repeat('x', $bytes));

        return ['path' => Storage::disk('local')->path('letter-attachments/'.$name), 'name' => $name];
    }

    public function test_files_over_the_limit_go_to_a_folder_in_the_matters_onedrive_folder_and_as_a_link_in_the_email(): void
    {
        $letter = $this->letter();
        $statement = $this->file('statement.pdf', 700 * 1024);
        $photos = $this->file('photos.zip', 600 * 1024);

        $result = app(LetterMailer::class)->send($letter, 'iflas', LetterMailer::ATTACHMENT, null, ['pdf'], attachments: [$statement, $photos]);

        $this->assertSame(['sent' => 1, 'failed' => 0, 'skipped' => 0, 'errors' => []], $result);
        // The letter attached, the files not.
        $names = array_map(fn ($part) => $part->getFilename(), $this->sent[0]->getAttachments());
        $this->assertCount(1, $names);
        $this->assertStringEndsWith('.pdf', $names[0]);

        // The files in a folder named after the letter, under the sent emails; a link for anyone made to it.
        $folderName = LetterIssuer::fileName($letter);
        $statementId = (string) array_key_first(array_filter($this->od->items, fn (array $item): bool => $item['name'] === 'statement.pdf'));
        $this->assertSame('Work/Matters/2026-123 - خبرة - محاكم دبي/'.MatterOneDriveFolders::DEFAULT_SENT_EMAILS.'/'.$folderName.'/statement.pdf', $this->od->pathOf($statementId));
        $this->assertNotEmpty(array_filter($this->od->items, fn (array $item): bool => $item['name'] === 'photos.zip'));
        $folderId = $this->od->items[$statementId]['parent'];
        $this->assertSame([[$folderId, 'anonymous', 'view']], $this->od->links);

        // The email ends with a note: how many, how big, the link and the files' names.
        $html = $this->sent[0]->getHtmlBody();
        $this->assertStringContainsString('المرفقات (2 ملفات، 1.3 م.ب) أكبر من أن تُرسل بالبريد الإلكتروني', $html);
        $this->assertStringContainsString('href="https://od.test/share/'.$folderId.'?s=anonymous"', $html);
        $this->assertStringContainsString('انقر هنا لتنزيل المرفقات', $html);
        $this->assertStringContainsString('<li>statement.pdf</li><li>photos.zip</li>', $html);

        // The kept PDF of the email lists them with the letter.
        $this->assertSame(1, $letter->matter->attachments()->count());
    }

    public function test_the_link_goes_where_the_covering_email_puts_it_and_the_placeholders_are_empty_otherwise(): void
    {
        $letter = $this->letter();
        $cover = EmailTemplate::create(['name' => 'Cover', 'purpose' => EmailTemplate::LETTER, 'locale' => 'ar', 'subject' => '{{reference}}', 'body' => '<p>نرفق الخطاب.</p><p>&lt;&lt;تجدون الملفات هنا: {{attachments.link}}&gt;&gt;</p><p>مع التحية.</p>', 'is_active' => true]);
        $small = $this->file('small.pdf', 10 * 1024);
        $big = $this->file('big.pdf', 1100 * 1024);

        // Under the limit: attached, no link made, the part written <<…>> left out.
        app(LetterMailer::class)->send($letter, 'iflas', LetterMailer::ATTACHMENT, $cover, ['pdf'], attachments: [$small]);
        $this->assertSame(['small.pdf'], array_slice(array_map(fn ($part) => $part->getFilename(), $this->sent[0]->getAttachments()), 1));
        $this->assertSame([], $this->od->links);
        $this->assertStringNotContainsString('تجدون الملفات هنا', $this->sent[0]->getHtmlBody());
        $this->assertStringNotContainsString('attachments.link', $this->sent[0]->getHtmlBody());

        // Over it: the link where the email puts it, and no note added.
        app(LetterMailer::class)->send($letter, 'iflas', LetterMailer::ATTACHMENT, $cover, ['pdf'], attachments: [$big]);
        $html = $this->sent[1]->getHtmlBody();
        $this->assertCount(1, $this->sent[1]->getAttachments());
        $this->assertMatchesRegularExpression('~تجدون الملفات هنا: <a href="https://od\.test/share/[^"]+">انقر هنا لتنزيل المرفقات</a>~u', $html);
        $this->assertStringNotContainsString('أكبر من أن تُرسل', $html);
        $this->assertCount(1, $this->od->links);
    }

    public function test_the_letter_as_the_email_gets_the_note_after_it(): void
    {
        $letter = $this->letter();
        $big = $this->file('big.pdf', 1100 * 1024);

        app(LetterMailer::class)->send($letter, 'iflas', LetterMailer::BODY, attachments: [$big]);

        $this->assertCount(0, $this->sent[0]->getAttachments());
        $html = $this->sent[0]->getHtmlBody();
        $this->assertStringContainsString('نص الخطاب.', $html);
        $this->assertStringContainsString('المرفقات (1 ملف، 1.1 م.ب)', $html);
        $this->assertStringContainsString('<li>big.pdf</li>', $html);
    }

    public function test_without_a_onedrive_folder_the_files_over_the_limit_stop_the_send(): void
    {
        $letter = $this->letter(withFolder: false);
        $big = $this->file('big.pdf', 1100 * 1024);

        $result = app(LetterMailer::class)->send($letter, 'iflas', LetterMailer::ATTACHMENT, null, ['pdf'], attachments: [$big]);

        $this->assertSame([], $this->sent);
        $this->assertSame([1, 0], [$result['failed'], $result['sent']]);
        $this->assertStringContainsString('The matter has no OneDrive folder', $result['errors'][0]);
        $this->assertStringContainsString('1.1 MB, over the 1 MB', $result['errors'][0]);
    }

    public function test_the_send_screen_says_when_the_files_will_go_by_link(): void
    {
        $this->assertNull(SharedAttachments::warning(900 * 1024));
        $this->assertStringContainsString('1.5 MB, over the 1 MB', (string) SharedAttachments::warning(1536 * 1024));
        $this->assertStringContainsString('Shared by link already, the link in the email: a.pdf · b.pdf', (string) SharedAttachments::warning(0, ['names' => ['a.pdf', 'b.pdf']]));
    }

    public function test_the_files_are_shared_in_the_background_with_their_progress_followed(): void
    {
        $letter = $this->letter();
        Storage::disk('local')->put('shared-attachments/x/0-a.pdf', str_repeat('a', 2048));
        Storage::disk('local')->put('shared-attachments/x/1-b.pdf', str_repeat('b', 2048));
        $staged = [['path' => Storage::disk('local')->path('shared-attachments/x/0-a.pdf'), 'name' => 'a.pdf'], ['path' => Storage::disk('local')->path('shared-attachments/x/1-b.pdf'), 'name' => 'b.pdf']];
        $steps = [];

        // Each step kept for the progress bar: copying each file, then the link, then done.
        $this->assertSame('', SharedAttachments::progressBar(null));
        SharedAttachments::setProgress($letter, SharedAttachments::QUEUED, 0, 2);
        $this->assertTrue(SharedAttachments::running($letter));
        $this->assertStringContainsString('Waiting to start', SharedAttachments::progressBar(SharedAttachments::progress($letter)));

        $shared = app(SharedAttachments::class)->shareNow($letter->matter, LetterMailer::attachedFiles($staged), LetterIssuer::fileName($letter), [], null, function (string $step, int $done, int $total, string $name) use (&$steps, $letter): void {
            $steps[] = "$step $done/$total $name";
            SharedAttachments::setProgress($letter, $step, $done, $total, $name);
            $steps[] = strip_tags(SharedAttachments::progressBar(SharedAttachments::progress($letter)));
        });
        $this->assertSame(['copying 0/2 a.pdf', 'Copying file 1 of 2: a.pdf', 'copying 1/2 b.pdf', 'Copying file 2 of 2: b.pdf', 'linking 2/2 ', 'Creating the link…'], $steps);
        $this->assertStringContainsString('width: 50%', SharedAttachments::progressBar(['step' => SharedAttachments::COPYING, 'done' => 1, 'total' => 2, 'name' => 'b.pdf', 'error' => null]));
        $this->assertSame(['a.pdf', 'b.pdf'], $shared['names']);

        // The job: the files shared, remembered on the letter, the staging folder removed, the bar gone (done).
        ShareAttachments::dispatchSync($letter, $staged, 'shared-attachments/x', LetterIssuer::fileName($letter), []);
        $this->assertSame(['a.pdf', 'b.pdf'], $letter->fresh()->shared_files['names']);
        $this->assertSame(SharedAttachments::DONE, SharedAttachments::progress($letter)['step']);
        $this->assertFalse(SharedAttachments::running($letter));
        $this->assertSame('', SharedAttachments::progressBar(SharedAttachments::progress($letter)));
        $this->assertFalse(Storage::disk('local')->exists('shared-attachments/x/0-a.pdf'));

        // Without a folder to share from: failed, said on the bar.
        $alone = $this->letter(withFolder: false);
        Storage::disk('local')->put('shared-attachments/y/0-c.pdf', 'c');
        ShareAttachments::dispatchSync($alone, [['path' => Storage::disk('local')->path('shared-attachments/y/0-c.pdf'), 'name' => 'c.pdf']], 'shared-attachments/y', 'x', []);
        $this->assertSame(SharedAttachments::FAILED, SharedAttachments::progress($alone)['step']);
        $this->assertStringContainsString('The matter has no OneDrive folder', SharedAttachments::progressBar(SharedAttachments::progress($alone)));
        $this->assertNull($alone->fresh()->shared_files);
    }

    public function test_the_link_can_be_had_before_sending_to_copy_by_hand_and_the_send_reuses_it(): void
    {
        $letter = $this->letter();
        $small = $this->file('small.pdf', 10 * 1024);
        $later = $this->file('later.pdf', 1100 * 1024);

        // "Get the link now": shared whether over the limit or not, remembered on the letter.
        $shared = app(SharedAttachments::class)->shareNow($letter->matter, LetterMailer::attachedFiles([$small]), LetterIssuer::fileName($letter), []);
        $letter->forceFill(['shared_files' => $shared])->save();
        $this->assertSame(['small.pdf'], $shared['names']);
        $this->assertCount(1, $this->od->links);

        // Pasted into the covering email by hand: the send, with no files, carries the link there and adds no note.
        $cover = EmailTemplate::create(['name' => 'Cover', 'purpose' => EmailTemplate::LETTER, 'locale' => 'ar', 'subject' => '{{reference}}', 'body' => '<p>الملفات: <a href="'.$shared['url'].'">هنا</a></p>', 'is_active' => true]);
        $result = app(LetterMailer::class)->send($letter, 'iflas', LetterMailer::ATTACHMENT, $cover, ['pdf']);
        $this->assertSame(['sent' => 1, 'failed' => 0, 'skipped' => 0, 'errors' => []], $result);
        $this->assertCount(1, $this->sent[0]->getAttachments());
        $this->assertStringContainsString('<a href="'.$shared['url'].'">هنا</a>', $this->sent[0]->getHtmlBody());
        $this->assertStringNotContainsString('أكبر من أن تُرسل', $this->sent[0]->getHtmlBody());

        // A file over the limit added at a later send: into the same folder, the same link — the note naming them all.
        app(LetterMailer::class)->send($letter, 'iflas', LetterMailer::ATTACHMENT, null, ['pdf'], attachments: [$later]);
        $this->assertSame(['small.pdf', 'later.pdf'], $letter->fresh()->shared_files['names']);
        $this->assertSame($shared['url'], $letter->fresh()->shared_files['url']);
        $this->assertCount(1, $this->od->links);
        $laterId = (string) array_key_first(array_filter($this->od->items, fn (array $item): bool => $item['name'] === 'later.pdf'));
        $smallId = (string) array_key_first(array_filter($this->od->items, fn (array $item): bool => $item['name'] === 'small.pdf'));
        $this->assertSame($this->od->items[$smallId]['parent'], $this->od->items[$laterId]['parent']);
        $this->assertStringContainsString('<li>small.pdf</li><li>later.pdf</li>', $this->sent[1]->getHtmlBody());
    }

    public function test_get_the_link_now_stages_the_files_added_for_the_job_and_they_come_out_shared(): void
    {
        $letter = $this->letter();
        $this->file('small.pdf', 10 * 1024);
        $this->file('big.pdf', 1100 * 1024);

        // Nothing added: nothing staged, and said so on the screen.
        $this->assertSame([], EmailSendFields::stage($letter->matter, ['attachments' => [], 'onedrive_files' => []])[1]);

        // The uploads, under their own names, in a folder of their own — the files as the job takes them (a path and a name).
        [$directory, $staged] = EmailSendFields::stage($letter->matter, [
            'attachments' => ['letter-attachments/small.pdf', 'letter-attachments/big.pdf'],
            'attachment_names' => ['letter-attachments/small.pdf' => 'الصغير.pdf'],
        ]);
        $this->assertStringStartsWith('shared-attachments/', $directory);
        $this->assertSame(['الصغير.pdf', 'big.pdf'], array_column($staged, 'name'));
        $this->assertSame([Storage::disk('local')->path($directory.'/0-الصغير.pdf'), Storage::disk('local')->path($directory.'/1-big.pdf')], array_column($staged, 'path'));
        $this->assertSame(1100 * 1024, Storage::disk('local')->size($directory.'/1-big.pdf'));

        ShareAttachments::dispatchSync($letter, $staged, $directory, LetterIssuer::fileName($letter), []);
        $this->assertSame(['الصغير.pdf', 'big.pdf'], $letter->fresh()->shared_files['names']);
        $this->assertSame(SharedAttachments::DONE, SharedAttachments::progress($letter)['step']);
        $this->assertFalse(Storage::disk('local')->exists($directory));
    }

    public function test_get_the_link_now_on_the_letters_tab_shares_the_files_added(): void
    {
        $letter = $this->letter();
        Storage::fake('local');

        Livewire::test(LettersRelationManager::class, ['ownerRecord' => $letter->matter, 'pageClass' => ViewMatter::class])
            ->mountTableAction('email', $letter)
            ->setTableActionData([
                'sender' => 'iflas',
                'recipients' => [$letter->recipients->first()->id],
                'attachments' => [UploadedFile::fake()->create('statement.pdf', 12, 'application/pdf')],
            ])
            // The button inside the send window: no window of its own, so pressing it runs it.
            ->call('mountAction', 'shareNow', [], ['schemaComponent' => 'mountedActionSchema0.shared_link'])
            ->assertHasNoErrors()
            ->assertSet('mountedActions.0.data.attachments', []);

        // Shared from the matter's folder, for the recipient ticked; remembered on the letter.
        $this->assertSame(['statement.pdf'], $letter->fresh()->shared_files['names']);
        $this->assertCount(1, $this->od->links);
        $this->assertSame(SharedAttachments::DONE, SharedAttachments::progress($letter)['step']);
    }

    public function test_the_link_opens_the_files_to_view_or_to_edit_as_set_and_forget_the_link_removes_the_folder(): void
    {
        $letter = $this->letter();
        $small = $this->file('small.pdf', 10 * 1024);

        // To view, unless set otherwise.
        $shared = app(SharedAttachments::class)->shareNow($letter->matter, LetterMailer::attachedFiles([$small]), LetterIssuer::fileName($letter), []);
        $this->assertSame('view', $this->od->links[0][2]);
        $letter->forceFill(['shared_files' => $shared])->save();
        $folderName = basename($shared['folder']);
        $this->assertCount(1, collect($this->od->items)->where('name', $folderName));

        // Forget the link: the folder, with the files, removed from OneDrive; the link dropped.
        $this->assertTrue(app(SharedAttachments::class)->forget($letter));
        $this->assertCount(0, collect($this->od->items)->where('name', $folderName));
        $this->assertNull($letter->fresh()->shared_files);
        // Forgotten already: nothing to remove.
        $this->assertFalse(app(SharedAttachments::class)->forget($letter->fresh()));

        // Set to edit: the next link lets those with it edit the files.
        Setting::set(SharedAttachments::LINK_TYPE, SharedAttachments::EDIT, 'mail');
        Setting::clearCache();
        app(SharedAttachments::class)->shareNow($letter->matter, LetterMailer::attachedFiles([$small]), LetterIssuer::fileName($letter), []);
        $this->assertSame('edit', $this->od->links[1][2]);
    }

    public function test_files_added_past_what_an_email_takes_are_shared_by_link_by_themselves(): void
    {
        $letter = $this->letter();
        Storage::fake('local');

        $screen = Livewire::test(LettersRelationManager::class, ['ownerRecord' => $letter->matter, 'pageClass' => ViewMatter::class])
            ->mountTableAction('email', $letter)
            ->setTableActionData(['sender' => 'iflas', 'recipients' => [$letter->recipients->first()->id]]);

        // Under the limit: attached as usual, nothing shared.
        $screen->setTableActionData(['attachments' => [UploadedFile::fake()->create('small.pdf', 100, 'application/pdf')]]);
        $this->assertNull($letter->fresh()->shared_files);
        $this->assertCount(1, $screen->get('mountedActions.0.data.attachments'));

        // Over it: shared by link the moment the file is there, the fields emptied, and said.
        $screen->setTableActionData(['attachments' => [UploadedFile::fake()->create('small.pdf', 100, 'application/pdf'), UploadedFile::fake()->create('big.pdf', 1100, 'application/pdf')]])
            ->assertNotified(__('More than an email takes: the files are shared by link'))
            ->assertSet('mountedActions.0.data.attachments', []);
        $this->assertSame(['small.pdf', 'big.pdf'], $letter->fresh()->shared_files['names']);
        $this->assertCount(1, $this->od->links);
    }
}
