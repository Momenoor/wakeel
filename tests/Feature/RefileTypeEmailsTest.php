<?php

namespace Tests\Feature;

use App\Filament\Mms\Resources\Types\Pages\EditType;
use App\Jobs\RefileTypeEmails;
use App\Models\Matter;
use App\Models\MatterEmail;
use App\Models\MatterOneDriveFolder;
use App\Models\Party;
use App\Models\Setting;
use App\Models\Type;
use App\Models\User;
use App\Services\MMS\ExistingEmailsRefiling;
use App\Services\MMS\MatterOneDriveFolders;
use App\Services\MMS\OneDriveClient;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\MemoryOneDrive;
use Tests\TestCase;

/**
 * A matter type's email folders or names changed, applied to its existing matters (RefileTypeEmails,
 * ExistingEmailsRefiling): each email filed already moved and renamed as the type says now, its record with it.
 */
class RefileTypeEmailsTest extends TestCase
{
    use RefreshDatabase;

    private Matter $matter;

    private Type $type;

    private MemoryOneDrive $oneDrive;

    protected function setUp(): void
    {
        parent::setUp();

        Gate::before(fn () => true);
        Filament::setCurrentPanel(Filament::getPanel('mms'));
        $this->actingAs(User::factory()->create());

        $this->oneDrive = new MemoryOneDrive('root-500');
        $this->app->instance(OneDriveClient::class, $this->oneDrive);

        $this->type = Type::factory()->create(['name' => 'تجاري', 'onedrive_received_emails' => '04 المراسلات']);
        $this->matter = Matter::factory()->create(['number' => '500', 'year' => '2026', 'type_id' => $this->type->id]);
        MatterOneDriveFolder::create([
            'matter_id' => $this->matter->id, 'folder_name' => '500-2026', 'status' => MatterOneDriveFolder::CREATED, 'drive_item_id' => 'root-500',
            'party_id' => Party::factory()->assistant()->create(['onedrive_email' => 'amr@firm.ae'])->id,
        ]);
        Setting::clearCache();
    }

    /** An email come in on the matter, its PDF and original in the old folder, under its old name. */
    private function received(string $stem, string $at): MatterEmail
    {
        $this->oneDrive->put('04 المراسلات/'.$stem.'.pdf');
        $this->oneDrive->put('04 المراسلات/'.$stem.'.eml');

        return MatterEmail::create([
            'matter_id' => $this->matter->id, 'direction' => MatterEmail::RECEIVED, 'status' => MatterEmail::FILED, 'sender_key' => 'office',
            'message_id' => uniqid().'@x.ae', 'subject' => 'مطالبة', 'from' => 'court@dubai.gov.ae', 'at' => Carbon::parse($at, config('app.timezone')),
            'files' => [['name' => $stem.'.pdf', 'path' => null]], 'onedrive' => ['pdf' => true, 'eml' => true, 'folder' => '04 المراسلات'],
        ]);
    }

    public function test_the_emails_of_existing_matters_are_moved_and_renamed_as_the_type_says_now(): void
    {
        $first = $this->received('قديم 1', '2026-10-30 10:00');
        $second = $this->received('قديم 2', '2026-10-31 11:30');

        // The folder and the name changed on the type.
        $this->type->update(['onedrive_received_emails' => '05 الوارد', 'received_name' => '{{date}} — {{subject}}']);

        (new RefileTypeEmails([$this->type->id], false, null))->handle(app(ExistingEmailsRefiling::class));

        $this->assertSame([
            '05 الوارد/2026-10-30 — مطالبة.eml', '05 الوارد/2026-10-30 — مطالبة.pdf',
            '05 الوارد/2026-10-31 — مطالبة.eml', '05 الوارد/2026-10-31 — مطالبة.pdf',
        ], $this->oneDrive->files());

        // Their records follow: where they are, and the PDF's name.
        $this->assertSame('05 الوارد', $first->fresh()->onedrive['folder']);
        $this->assertSame('2026-10-30 — مطالبة.pdf', $first->fresh()->files[0]['name']);
        $this->assertSame('2026-10-31 — مطالبة.pdf', $second->fresh()->files[0]['name']);

        // Run again: where they should be already — nothing moves.
        $this->assertSame(['moved' => 0, 'renamed' => 0, 'failed' => 0], app(ExistingEmailsRefiling::class)->refile($this->matter->fresh()));
    }

    public function test_only_the_name_changed_renames_them_where_they_are(): void
    {
        $email = $this->received('قديم', '2026-10-30 10:00');
        $this->type->update(['received_name' => '{{subject}} {{date}}']);

        $this->assertSame(['moved' => 0, 'renamed' => 1, 'failed' => 0], app(ExistingEmailsRefiling::class)->refile($this->matter->fresh()));
        $this->assertSame(['04 المراسلات/مطالبة 2026-10-30.eml', '04 المراسلات/مطالبة 2026-10-30.pdf'], $this->oneDrive->files());
        $this->assertSame('04 المراسلات', $email->fresh()->onedrive['folder']);
    }

    public function test_a_sent_email_moves_to_the_new_sent_folder_and_keeps_its_name(): void
    {
        $this->type->update(['onedrive_sent_emails' => '03 الصادر']);
        $this->oneDrive->put('03 الصادر/صادر 1.pdf');
        $sent = MatterEmail::create([
            'matter_id' => $this->matter->id, 'direction' => MatterEmail::SENT, 'status' => MatterEmail::FILED, 'sender_key' => 'office',
            'message_id' => uniqid().'@x.ae', 'subject' => 'إخطار', 'from' => 'office@firm.ae', 'to' => ['court@dubai.gov.ae'], 'at' => now(),
            'files' => [['name' => 'صادر 1.pdf', 'path' => null]], 'onedrive' => ['pdf' => true, 'folder' => '03 الصادر'],
        ]);

        $this->type->update(['onedrive_sent_emails' => '06 الصادر الجديد']);

        $this->assertSame(['moved' => 1, 'renamed' => 0, 'failed' => 0], app(ExistingEmailsRefiling::class)->refile($this->matter->fresh()));
        $this->assertSame(['06 الصادر الجديد/صادر 1.pdf'], $this->oneDrive->files());
        $this->assertSame('06 الصادر الجديد', $sent->fresh()->onedrive['folder']);
    }

    public function test_emails_put_in_onedrive_by_hand_are_left_alone(): void
    {
        $email = $this->received('بيدي', '2026-10-30 10:00');
        $email->update(['matched_by' => MatterEmail::BY_ONEDRIVE]);
        $this->type->update(['onedrive_received_emails' => '05 الوارد']);

        $this->assertSame(['moved' => 0, 'renamed' => 0, 'failed' => 0], app(ExistingEmailsRefiling::class)->refile($this->matter->fresh()));
        $this->assertSame(['04 المراسلات/بيدي.eml', '04 المراسلات/بيدي.pdf'], $this->oneDrive->files());
    }

    public function test_saving_a_type_with_its_email_folder_changed_offers_to_apply_it(): void
    {
        Queue::fake();
        Setting::set(MatterOneDriveFolders::ENABLED, '1', 'onedrive');
        Setting::clearCache();

        $page = Livewire::test(EditType::class, ['record' => $this->type->getRouteKey()])
            ->fillForm(['onedrive_received_emails' => '05 الوارد'])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertSet('emailFilingChanged', true);

        // Only when asked: then on the queue, for this type.
        Queue::assertNothingPushed();
        $page->callAction('refileEmails', ['finished' => true]);
        Queue::assertPushed(RefileTypeEmails::class, fn (RefileTypeEmails $job): bool => $job->typeIds === [$this->type->id] && $job->finishedToo);
    }
}
