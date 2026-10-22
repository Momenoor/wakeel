<?php

namespace Tests\Feature;

use App\Enums\AttachmentCategory;
use App\Enums\SignatureStampKind;
use App\Filament\Mms\RelationManagers\AttachmentsRelationManager;
use App\Filament\Mms\Resources\Matters\Pages\ViewMatter;
use App\Filament\Shared\Pages\IntegrationsSettings;
use App\Jobs\CopyFilesToAzure;
use App\Models\Attachment;
use App\Models\Fee;
use App\Models\Matter;
use App\Models\MatterEmail;
use App\Models\Setting;
use App\Models\SignatureStamp;
use App\Models\User;
use App\Services\Attachments\AttachmentService;
use App\Services\Attachments\CopyToAzure;
use App\Services\MMS\SentEmailArchive;
use App\Support\Documents;
use App\Support\Integrations;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Azure Blob Storage, from Settings → Integrations: where new attached files, signatures and stamps are kept
 * once chosen, and where those kept on this server are copied to — each checked, each opening from where it is.
 */
class AzureStorageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('mms'));
        $admin = User::factory()->create();
        $admin->assignRole(Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']));
        $this->actingAs($admin);
    }

    private function connect(bool $newFiles = true): void
    {
        Integrations::save([
            Integrations::AZURE_ACCOUNT => 'wakeelfiles', Integrations::AZURE_KEY => base64_encode('key'),
            Integrations::AZURE_CONTAINER => 'wakeel', Integrations::AZURE_ATTACHMENTS => $newFiles ? '1' : '0',
        ]);
        Integrations::apply();
        Storage::fake(Integrations::AZURE_DISK);
        Storage::fake(Integrations::AZURE_PUBLIC_DISK);
    }

    public function test_set_on_the_integrations_page_it_keeps_new_files_once_chosen(): void
    {
        $this->assertFalse(Integrations::azureOn());

        Livewire::test(IntegrationsSettings::class)
            ->fillForm([Integrations::AZURE_ACCOUNT => 'wakeelfiles', Integrations::AZURE_KEY => 'secret-key', Integrations::AZURE_CONTAINER => 'wakeel'])
            ->call('save')
            ->assertHasNoFormErrors();

        // Its key kept encrypted; the disk set; new files still here until chosen.
        $this->assertNotSame('secret-key', Setting::query()->where('key', Integrations::AZURE_KEY)->value('value'));
        $this->assertTrue(Integrations::azureOn());
        $this->assertSame(['shared_key', 'wakeelfiles', 'secret-key', 'wakeel'], [
            config('filesystems.disks.azure.credential'), config('filesystems.disks.azure.account_name'),
            config('filesystems.disks.azure.account_key'), config('filesystems.disks.azure.container'),
        ]);
        $this->assertSame('attachments', config('attachments.disk'));

        Livewire::test(IntegrationsSettings::class)
            ->fillForm([Integrations::AZURE_ATTACHMENTS => true])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(Integrations::AZURE_DISK, config('attachments.disk'));
        $this->assertSame(Integrations::AZURE_DISK, SignatureStamp::disk());
    }

    public function test_the_files_here_are_copied_each_checked_and_then_open_from_azure(): void
    {
        Storage::fake('attachments');
        Storage::fake('public');
        $matter = Matter::factory()->create();
        $file = fn (string $disk, string $path, ?string $contents, ?string $checksum = null): Attachment => Attachment::create([
            'matter_id' => $matter->id, 'user_id' => auth()->id(), 'name' => basename($path), 'path' => $path, 'disk' => $disk,
            'size' => '1', 'extension' => 'pdf', 'checksum' => $checksum ?? ($contents !== null ? hash('sha256', $contents) : null),
        ]);

        Storage::disk('attachments')->put('matter/2026/1/a.pdf', '%PDF-a');
        $here = $file('attachments', 'matter/2026/1/a.pdf', '%PDF-a');
        $missing = $file('attachments', 'matter/2026/1/gone.pdf', null);
        Storage::disk('attachments')->put('matter/2026/1/b.pdf', '%PDF-changed');
        $changed = $file('attachments', 'matter/2026/1/b.pdf', null, hash('sha256', '%PDF-b'));
        Storage::disk('public')->put('old/c.pdf', '%PDF-c');
        $public = $file('public', 'old/c.pdf', '%PDF-c');
        Storage::disk('attachments')->put('signatures/s.png', 'png');
        $stamp = SignatureStamp::create(['name' => 'S', 'kind' => SignatureStampKind::SIGNATURE, 'user_id' => auth()->id(), 'is_shared' => false, 'path' => 'signatures/s.png', 'disk' => 'attachments', 'width_mm' => 40]);

        // New files go there; those before stay where they are until copied.
        $this->connect();
        $this->assertSame(5, CopyToAzure::remaining());

        $this->artisan('attachments:to-azure')->assertExitCode(1);

        // Copied and pointed there; the originals kept.
        $this->assertSame(['azure', '%PDF-a'], [$here->fresh()->disk, $here->fresh()->storage()->get($here->path)]);
        $this->assertSame('azure', $stamp->fresh()->disk);
        $this->assertTrue(Storage::disk('attachments')->exists('matter/2026/1/a.pdf'));
        // Missing, or not the file recorded: left as they are.
        $this->assertSame('attachments', $missing->fresh()->disk);
        $this->assertSame('attachments', $changed->fresh()->disk);
        $this->assertFalse(Storage::disk('azure')->exists('matter/2026/1/b.pdf'));
        // A document of the public disk: moved — there, and no longer here.
        $this->assertSame([Integrations::AZURE_PUBLIC_DISK, '%PDF-c'], [$public->fresh()->disk, $public->fresh()->storage()->get('old/c.pdf')]);
        $this->assertFalse(Storage::disk('public')->exists('old/c.pdf'));
        $this->assertSame(2, CopyToAzure::remaining());

        // Opened from Azure.
        $this->get(route('attachment.download', $here))->assertOk();
    }

    public function test_the_emails_and_minutes_files_go_to_azure_and_open_at_their_address_for_who_may_see_them(): void
    {
        Storage::fake('public');
        $matter = Matter::factory()->create();
        // Before: kept here, its row on the public disk; an unfiled email's file, here too.
        $before = app(SentEmailArchive::class)->keepFile($matter, 'رد.pdf', '%PDF-before', 'application/pdf', '', auth()->id());
        Storage::disk('public')->put('email-unfiled/1/x.pdf', '%PDF-unfiled');
        MatterEmail::create(['direction' => MatterEmail::RECEIVED, 'status' => MatterEmail::PENDING, 'sender_key' => 'iflas', 'subject' => 's', 'from' => 'a@b.ae', 'at' => now(), 'files' => [['name' => 'x.pdf', 'path' => 'email-unfiled/1/x.pdf']]]);
        $this->assertSame('public', Attachment::where('path', $before)->value('disk'));

        // New files in Azure: a new one is kept there, its row says so.
        $this->connect();
        $after = app(SentEmailArchive::class)->keepFile($matter, 'رد 2.pdf', '%PDF-after', 'application/pdf', '', auth()->id());
        $this->assertSame([Integrations::AZURE_PUBLIC_DISK, '%PDF-after'], [Attachment::where('path', $after)->value('disk'), Storage::disk('azure-public')->get($after)]);
        $this->assertFalse(Storage::disk('public')->exists($after));
        $this->assertSame('%PDF-before', Documents::storage($before)->get($before));

        // Those before, moved.
        $this->assertSame(2, CopyToAzure::remaining());
        $this->artisan('attachments:to-azure')->assertExitCode(0);
        $this->assertSame(Integrations::AZURE_PUBLIC_DISK, Attachment::where('path', $before)->value('disk'));
        $this->assertSame(['%PDF-before', '%PDF-unfiled'], [Documents::storage($before)->get($before), Documents::storage('email-unfiled/1/x.pdf')->get('email-unfiled/1/x.pdf')]);
        $this->assertFalse(Storage::disk('public')->exists($before));
        $this->assertSame(0, CopyToAzure::remaining());

        // At their old address, through Wakeel: for who may see them.
        $this->get('/storage/'.$before)->assertOk();
        $this->get('/storage/email-unfiled/1/x.pdf')->assertOk();
        $this->get('/storage/email-unfiled/1/none.pdf')->assertNotFound();
        $this->actingAs(User::factory()->create());
        $this->get('/storage/'.$before)->assertForbidden();
        $this->get('/storage/email-unfiled/1/x.pdf')->assertForbidden();

        // A file of no row anywhere else: nobody's to open.
        Storage::disk('azure-public')->put('branding/other.pdf', 'x');
        $this->get('/storage/branding/other.pdf')->assertForbidden();

        // Azure holds files: it cannot be turned off (they would no longer open).
        $this->assertTrue(CopyToAzure::anyInAzure());
    }

    public function test_a_deleted_evidence_files_copy_in_azure_opens_only_for_its_matters_people(): void
    {
        Storage::fake('public');
        $matter = Matter::factory()->create();
        $this->connect();
        $path = app(SentEmailArchive::class)->keepFile($matter, 'محضر.pdf', '%PDF-old', 'application/pdf', '', auth()->id());
        // Evidence, deleted: hidden, its file kept.
        $file = Attachment::where('path', $path)->first();
        $file->update(['category' => AttachmentCategory::TAX_INVOICE]);
        $file->delete();
        $this->assertSoftDeleted($file);

        $this->get('/storage/'.$path)->assertOk();
        $this->actingAs(User::factory()->create()->givePermissionTo(Permission::findOrCreate('View:UnfiledEmails')));
        $this->get('/storage/'.$path)->assertForbidden();
    }

    public function test_asked_to_it_deletes_each_original_once_copied(): void
    {
        Storage::fake('attachments');
        Storage::disk('attachments')->put('matter/2026/1/a.pdf', '%PDF-a');
        $here = Attachment::create(['matter_id' => Matter::factory()->create()->id, 'user_id' => auth()->id(), 'name' => 'a.pdf', 'path' => 'matter/2026/1/a.pdf', 'disk' => 'attachments', 'size' => '1', 'extension' => 'pdf']);
        $this->connect(newFiles: false);

        $this->artisan('attachments:to-azure', ['--delete-local' => true])->assertExitCode(0);

        $this->assertSame('azure', $here->fresh()->disk);
        $this->assertFalse(Storage::disk('attachments')->exists('matter/2026/1/a.pdf'));
        $this->assertSame('attachments', config('attachments.disk'));
    }

    public function test_one_press_copies_them_all_in_the_background_and_tells_who_started_it(): void
    {
        Storage::fake('attachments');
        $matter = Matter::factory()->create();
        foreach (['a', 'b', 'c'] as $name) {
            Storage::disk('attachments')->put("matter/2026/1/{$name}.pdf", "%PDF-{$name}");
            Attachment::create(['matter_id' => $matter->id, 'user_id' => auth()->id(), 'name' => "{$name}.pdf", 'path' => "matter/2026/1/{$name}.pdf", 'disk' => 'attachments', 'size' => '1', 'extension' => 'pdf']);
        }
        $this->connect();

        // Its turns: all copied, then whoever started it told.
        (new CopyFilesToAzure(auth()->id()))->handle(app(CopyToAzure::class));

        $this->assertSame(3, Attachment::where('disk', Integrations::AZURE_DISK)->count());
        $this->assertFalse(CopyFilesToAzure::running());
        $this->assertSame(1, auth()->user()->notifications()->count());

        // Started once; pressed again meanwhile, not twice.
        Queue::fake();
        $this->assertTrue(CopyFilesToAzure::start(auth()->id()));
        $this->assertFalse(CopyFilesToAzure::start(auth()->id()));
        Queue::assertPushed(CopyFilesToAzure::class, 1);
    }

    public function test_the_copies_left_here_go_once_checked_and_each_file_shows_where_it_is(): void
    {
        Storage::fake('attachments');
        $fee = Fee::factory()->create(['matter_id' => Matter::factory()->create()->id]);
        $store = fn (string $name): Attachment => app(AttachmentService::class)->store($fee, '%PDF-'.$name, AttachmentCategory::TAX_INVOICE, $name.'.pdf');
        $same = $store('same');
        $changed = $store('changed');
        $this->connect(newFiles: false);
        app(CopyToAzure::class)->run();
        // A file attached after, kept here (new files not in Azure).
        $here = $store('here');
        // Azure's copy of one is not the file here: that one's copy here stays.
        Storage::disk('azure')->put($changed->path, 'not it');

        $this->assertEqualsCanonicalizing([$same->path, $changed->path], CopyToAzure::leftHere());
        $this->assertSame(['azure' => 2, 'here' => 1], CopyToAzure::counts()[__('Attached files (with emails\' and minutes\' files)')]);

        // Each file says where it is.
        Livewire::test(AttachmentsRelationManager::class, ['ownerRecord' => $fee, 'pageClass' => ViewMatter::class])
            ->assertTableColumnStateSet('disk', __('Azure'), $same)
            ->assertTableColumnStateSet('disk', __('This server'), $here)
            ->filterTable('kept_in', 'here')
            ->assertCanSeeTableRecords([$here])
            ->assertCanNotSeeTableRecords([$same, $changed]);

        // In the background, then told.
        (new CopyFilesToAzure(auth()->id(), removeLocal: true))->handle(app(CopyToAzure::class));

        $this->assertFalse(Storage::disk('attachments')->exists($same->path));
        $this->assertTrue(Storage::disk('attachments')->exists($changed->path));
        $this->assertTrue(Storage::disk('attachments')->exists($here->path));
        $this->assertSame([$changed->path], CopyToAzure::leftHere());
        $this->assertSame('%PDF-same', $same->fresh()->storage()->get($same->path));
        $this->assertSame(1, auth()->user()->notifications()->count());
    }

    public function test_one_copy_at_a_time(): void
    {
        $this->connect();
        $running = Cache::lock('copy-to-azure', 60);
        $running->get();

        // A second press meanwhile waited on the rows the first was pointing at Azure: now it is turned back.
        $this->assertNull(app(CopyToAzure::class)->run());
        $this->artisan('attachments:to-azure')->assertExitCode(1);

        $running->release();
        $this->assertSame(0, app(CopyToAzure::class)->run()['copied']);
    }

    public function test_without_azure_there_is_nothing_to_copy_to(): void
    {
        $this->artisan('attachments:to-azure')->assertExitCode(1);
    }
}
