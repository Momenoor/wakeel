<?php

namespace Tests\Feature;

use App\Filament\Mms\Resources\Matters\Pages\ViewMatter;
use App\Filament\Mms\Resources\Matters\RelationManagers\EmailsRelationManager;
use App\Jobs\CheckMatterEmailsInOneDrive;
use App\Models\Matter;
use App\Models\MatterEmail;
use App\Models\MatterOneDriveFolder;
use App\Models\Party;
use App\Models\Setting;
use App\Models\User;
use App\Services\MMS\MatterOneDriveFolders;
use App\Services\MMS\OneDriveClient;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\Support\MemoryOneDrive;
use Tests\TestCase;

/**
 * "Check OneDrive" on a matter's Emails tab reads OneDrive on the queue — it took up to half a minute in the
 * page — and tells whoever asked in the bell.
 */
class OneDriveCheckQueuedTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Matter $matter;

    protected function setUp(): void
    {
        parent::setUp();

        Gate::before(fn () => true);
        Filament::setCurrentPanel(Filament::getPanel('mms'));
        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        $this->matter = Matter::factory()->create(['number' => '321', 'year' => '2026']);
    }

    public function test_the_button_queues_the_check(): void
    {
        Bus::fake();

        Livewire::test(EmailsRelationManager::class, ['ownerRecord' => $this->matter, 'pageClass' => ViewMatter::class])
            ->callAction(TestAction::make('checkAllOneDrive')->table())
            ->assertNotified(__('Checking OneDrive'));

        Bus::assertDispatched(CheckMatterEmailsInOneDrive::class, fn (CheckMatterEmailsInOneDrive $job): bool => $job->matterId === $this->matter->id && $job->userId === $this->user->id);
    }

    public function test_the_job_reads_onedrive_and_tells_in_the_bell(): void
    {
        $oneDrive = new MemoryOneDrive('root-321');
        $this->app->instance(OneDriveClient::class, $oneDrive);
        MatterOneDriveFolder::create([
            'matter_id' => $this->matter->id, 'folder_name' => '321-2026', 'status' => MatterOneDriveFolder::CREATED, 'drive_item_id' => 'root-321',
            'party_id' => Party::factory()->assistant()->create(['onedrive_email' => 'amr@firm.ae'])->id,
        ]);
        Setting::set(MatterOneDriveFolders::RECEIVED_EMAILS, '04 المراسلات', 'onedrive');
        Setting::clearCache();

        $oneDrive->put('04 المراسلات/Claim.pdf');
        $email = MatterEmail::create([
            'matter_id' => $this->matter->id, 'direction' => MatterEmail::RECEIVED, 'status' => MatterEmail::FILED, 'sender_key' => 'iflas',
            'message_id' => 'q-1@x.ae', 'subject' => 'Claim', 'from' => 'a@x.ae', 'at' => now(),
            'files' => [['name' => 'Claim.pdf', 'path' => null]], 'onedrive' => ['pdf' => false, 'folder' => '04 المراسلات'],
        ]);

        (new CheckMatterEmailsInOneDrive($this->matter->id, $this->user->id))->handle(app(MatterOneDriveFolders::class));

        $this->assertTrue((bool) $email->fresh()->onedrive['pdf']);
        $this->assertSame(__('OneDrive checked'), $this->user->notifications()->sole()->data['title']);

        // No OneDrive folder: said so, not failed.
        MatterOneDriveFolder::query()->where('matter_id', $this->matter->id)->delete();
        $this->user->notifications()->delete();
        (new CheckMatterEmailsInOneDrive($this->matter->id, $this->user->id))->handle(app(MatterOneDriveFolders::class));
        $this->assertSame(__('Nothing to check'), $this->user->notifications()->sole()->data['title']);
    }
}
