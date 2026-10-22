<?php

namespace Tests\Feature;

use App\Enums\SignatureStampKind;
use App\Filament\Shared\Pages\IntegrationsSettings;
use App\Models\Attachment;
use App\Models\Matter;
use App\Models\Setting;
use App\Models\SignatureStamp;
use App\Models\User;
use App\Services\Attachments\CopyToAzure;
use App\Support\Integrations;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
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
        $this->assertSame(4, CopyToAzure::remaining());

        $this->artisan('attachments:to-azure')->assertExitCode(1);

        // Copied and pointed there; the originals kept.
        $this->assertSame(['azure', '%PDF-a'], [$here->fresh()->disk, $here->fresh()->storage()->get($here->path)]);
        $this->assertSame('azure', $stamp->fresh()->disk);
        $this->assertTrue(Storage::disk('attachments')->exists('matter/2026/1/a.pdf'));
        // Missing, or not the file recorded: left as they are.
        $this->assertSame('attachments', $missing->fresh()->disk);
        $this->assertSame('attachments', $changed->fresh()->disk);
        $this->assertFalse(Storage::disk('azure')->exists('matter/2026/1/b.pdf'));
        // On the public disk: for attachments:move first.
        $this->assertSame('public', $public->fresh()->disk);
        $this->assertSame(2, CopyToAzure::remaining());

        // Opened from Azure.
        $this->get(route('attachment.download', $here))->assertOk();
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

    public function test_without_azure_there_is_nothing_to_copy_to(): void
    {
        $this->artisan('attachments:to-azure')->assertExitCode(1);
    }
}
