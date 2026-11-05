<?php

namespace Tests\Feature;

use App\Filament\Mms\Resources\Matters\Pages\ViewMatter;
use App\Filament\Mms\Resources\Matters\RelationManagers\EmailsRelationManager;
use App\Livewire\MatterContacts;
use App\Models\Matter;
use App\Models\MatterContact;
use App\Models\MatterContactDetail;
use App\Models\MatterEmail;
use App\Models\MatterOneDriveFolder;
use App\Models\MatterProgress;
use App\Models\Party;
use App\Models\Setting;
use App\Models\User;
use App\Services\MMS\MatterContactsExport;
use App\Services\MMS\MatterOneDriveFolders;
use App\Services\MMS\MatterProgressRecorder;
use App\Services\MMS\OneDriveClient;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Support\MemoryOneDrive;
use Tests\TestCase;

/**
 * A super admin deletes an email from a matter's Emails tab, or someone who came by email from its Contacts tab,
 * with everything of them: their files in the system and in the matter's OneDrive, and what was learnt from them.
 */
class MatterEmailDeletionTest extends TestCase
{
    use RefreshDatabase;

    private Matter $matter;

    private MemoryOneDrive $oneDrive;

    protected function setUp(): void
    {
        parent::setUp();

        Gate::before(fn () => true);
        Filament::setCurrentPanel(Filament::getPanel('mms'));
        Storage::fake('public');
        app()->setLocale('ar');

        $this->oneDrive = new MemoryOneDrive('root-986');
        $this->app->instance(OneDriveClient::class, $this->oneDrive);

        $this->matter = Matter::factory()->create(['number' => '986', 'year' => '2026']);
        MatterOneDriveFolder::create([
            'matter_id' => $this->matter->id, 'folder_name' => '986-2026', 'status' => MatterOneDriveFolder::CREATED, 'drive_item_id' => 'root-986',
            'party_id' => Party::factory()->assistant()->create(['onedrive_email' => 'amr@firm.ae'])->id,
        ]);
        Setting::set(MatterOneDriveFolders::RECEIVED_EMAILS, '04 المراسلات', 'onedrive');
        Setting::clearCache();
    }

    private function superAdmin(): void
    {
        $this->actingAs(User::factory()->create()->assignRole(Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web'])));
    }

    /**
     * An email come in on the matter, as kept: its PDF and a file it came with in the system, its PDF and original in
     * OneDrive, the file it came with in an attachments folder, and a copy of it all in another folder; a step in the
     * progress, and an address it brought in.
     */
    private function received(string $from, string $stem, array $cc = []): MatterEmail
    {
        foreach (['04 المراسلات/'.$stem.'.pdf', '04 المراسلات/'.$stem.'.eml', '02 المستندات/'.$stem.' عقد.pdf', '05 نسخة/'.$stem.'.pdf', '05 نسخة/'.$stem.'/'.$stem.' عقد.pdf'] as $path) {
            $this->oneDrive->put($path);
        }
        foreach (['attachments/emails/'.$stem.'.pdf', 'attachments/emails/'.$stem.'-1.pdf'] as $path) {
            Storage::disk('public')->put($path, 'x');
            $this->matter->attachments()->create(['user_id' => null, 'type' => 'correspondence', 'path' => $path, 'disk' => 'public', 'name' => basename($path), 'size' => 1, 'extension' => 'pdf']);
        }

        $email = MatterEmail::create([
            'matter_id' => $this->matter->id, 'direction' => MatterEmail::RECEIVED, 'status' => MatterEmail::FILED, 'sender_key' => 'office',
            'message_id' => uniqid().'@x.ae', 'subject' => 'مطالبة', 'from' => $from, 'to' => ['office@firm.ae'], 'cc' => $cc, 'at' => now(),
            'files' => [['name' => $stem.'.pdf', 'path' => 'attachments/emails/'.$stem.'.pdf'], ['name' => $stem.' عقد.pdf', 'path' => 'attachments/emails/'.$stem.'-1.pdf']],
            'onedrive' => ['pdf' => true, 'eml' => true, 'folder' => '04 المراسلات', 'attachments_folder' => '02 المستندات', 'copies' => [['side' => 'creditor', 'folder' => '05 نسخة', 'pdf' => true]]],
        ]);
        MatterProgressRecorder::emailReceived($email, null);
        MatterContact::learn($email);

        return $email;
    }

    public function test_only_a_super_admin_may_delete_an_email_or_a_contact(): void
    {
        $this->actingAs(User::factory()->create());
        $email = $this->received('lawyer@hashmi.ae', 'وارد 1');

        Livewire::test(EmailsRelationManager::class, ['ownerRecord' => $this->matter, 'pageClass' => ViewMatter::class])
            ->assertActionHidden(TestAction::make('delete')->table($email));
        Livewire::test(MatterContacts::class, ['matter' => $this->matter])
            ->assertTableActionHidden('deleteContact', 'e:lawyer@hashmi.ae');
    }

    public function test_an_email_is_deleted_with_all_its_files_and_its_replies_stay(): void
    {
        $this->superAdmin();
        $email = $this->received('lawyer@hashmi.ae', 'وارد 1', ['partner@hashmi.ae']);
        $other = $this->received('court@dubai.gov.ae', 'وارد 2');
        // Sent, with a reply to it.
        $sent = MatterEmail::create(['matter_id' => $this->matter->id, 'direction' => MatterEmail::SENT, 'sender_key' => 'office', 'subject' => 'كتاب', 'to' => ['court@dubai.gov.ae'], 'at' => now()]);
        $other->update(['parent_id' => $sent->id]);
        $this->assertSame(1, MatterContact::query()->where('matter_email_id', $email->id)->where('email', 'partner@hashmi.ae')->count());

        Livewire::test(EmailsRelationManager::class, ['ownerRecord' => $this->matter, 'pageClass' => ViewMatter::class])
            ->callAction(TestAction::make('delete')->table($email))
            ->callAction(TestAction::make('delete')->table($sent))
            ->assertHasNoErrors();

        $this->assertModelMissing($email);
        $this->assertModelMissing($sent);
        // Its PDF, original, the file it came with apart and its copy gone; the other's stay.
        $this->assertSame([
            '02 المستندات/وارد 2 عقد.pdf', '04 المراسلات/وارد 2.eml', '04 المراسلات/وارد 2.pdf',
            '05 نسخة/وارد 2.pdf', '05 نسخة/وارد 2/وارد 2 عقد.pdf',
        ], $this->oneDrive->files());
        Storage::disk('public')->assertMissing(['attachments/emails/وارد 1.pdf', 'attachments/emails/وارد 1-1.pdf']);
        Storage::disk('public')->assertExists('attachments/emails/وارد 2.pdf');
        $this->assertEqualsCanonicalizing(['وارد 2.pdf', 'وارد 2-1.pdf'], $this->matter->attachments()->pluck('name')->all());
        $this->assertSame(0, MatterProgress::query()->where('source_id', $email->id)->where('source_type', $email->getMorphClass())->count());
        $this->assertSame(0, MatterContact::query()->where('email', 'partner@hashmi.ae')->count());
        // The reply to the email sent stays, an email of its own.
        $this->assertNull($other->fresh()->parent_id);
    }

    public function test_someone_who_came_by_email_is_deleted_with_the_emails_from_them(): void
    {
        $this->superAdmin();
        $first = $this->received('مكتب الهاشمي <lawyer@hashmi.ae>', 'وارد 1', ['partner@hashmi.ae']);
        $second = $this->received('lawyer@hashmi.ae', 'وارد 2');
        $other = $this->received('court@dubai.gov.ae', 'وارد 3', ['lawyer@hashmi.ae']);
        MatterContactDetail::create(['matter_id' => $this->matter->id, 'email' => 'lawyer@hashmi.ae', 'name' => 'مكتب الهاشمي', 'capacity' => 'محامٍ']);

        Livewire::test(MatterContacts::class, ['matter' => $this->matter])
            ->assertTableActionVisible('deleteContact', 'e:lawyer@hashmi.ae')
            ->callTableAction('deleteContact', 'e:lawyer@hashmi.ae')
            ->assertHasNoTableActionErrors()
            ->assertNotified(__('The contact is deleted'));

        $this->assertModelMissing($first);
        $this->assertModelMissing($second);
        $this->assertModelExists($other);
        $this->assertSame(['02 المستندات/وارد 3 عقد.pdf', '04 المراسلات/وارد 3.eml', '04 المراسلات/وارد 3.pdf', '05 نسخة/وارد 3.pdf', '05 نسخة/وارد 3/وارد 3 عقد.pdf'], $this->oneDrive->files());
        // The addresses they brought in, and the one the court's brought them in as: gone; their details too.
        $this->assertSame(0, MatterContact::query()->where('matter_id', $this->matter->id)->where(fn ($q) => $q->whereIn('owner_email', ['lawyer@hashmi.ae'])->orWhereIn('email', ['lawyer@hashmi.ae', 'partner@hashmi.ae']))->count());
        $this->assertSame(0, MatterContactDetail::query()->count());
        $this->assertArrayNotHasKey('e:lawyer@hashmi.ae', collect((new MatterContactsExport($this->matter))->rows())->keyBy('key')->all());
    }
}
