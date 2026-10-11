<?php

namespace Tests\Feature;

use App\Enums\RequestType;
use App\Filament\Mms\Resources\Matters\Pages\ViewMatter;
use App\Filament\Mms\Resources\Matters\RelationManagers\LettersRelationManager;
use App\Filament\Support\EmailSendFields;
use App\Livewire\MatterOneDriveFiles;
use App\Models\Letterhead;
use App\Models\LetterTemplate;
use App\Models\Matter;
use App\Models\MatterOneDriveFolder;
use App\Models\Party;
use App\Models\User;
use App\Services\MMS\Letters\LetterIssuer;
use App\Services\MMS\MatterOneDriveExplorer;
use App\Services\MMS\SentFolder;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Mockery;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * A matter's OneDrive folder as a file manager on its Files tab — and
 * nothing outside that folder.
 */
class MatterOneDriveFilesTest extends TestCase
{
    use RefreshDatabase;

    private Matter $matter;

    private MatterOneDriveFolder $folder;

    private bool $allowAll = true;

    /** @var list<array{method: string, url: string, data: array}> */
    private array $changes = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['mail.mailers.microsoft-graph' => ['transport' => 'microsoft-graph', 'tenant_id' => 'tenant', 'client_id' => 'client', 'client_secret' => 'secret']]);
        Filament::setCurrentPanel(Filament::getPanel('mms'));
        // Everything allowed — or, as an assistant, only seeing the matter.
        Gate::before(fn ($user, string $ability) => $this->allowAll || $ability === 'view' ? true : null);

        Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        $this->actingAs(User::factory()->create()->assignRole('super-admin'));

        $this->matter = Matter::factory()->create(['number' => '123', 'year' => '2026']);
        $this->folder = MatterOneDriveFolder::create([
            'matter_id' => $this->matter->id,
            'party_id' => Party::factory()->assistant()->create(['name' => 'Amr', 'onedrive_email' => 'amr@firm.ae'])->id,
            'folder_name' => '123-2026', 'status' => MatterOneDriveFolder::CREATED, 'drive_item_id' => 'root-id', 'web_url' => 'https://od/root',
        ]);

        $inside = '/drive/root:/Work/Matters/123-2026';
        $items = [
            'root-id' => ['id' => 'root-id', 'name' => '123-2026', 'folder' => ['childCount' => 2], 'parentReference' => ['path' => '/drive/root:/Work/Matters']],
            'sub-id' => ['id' => 'sub-id', 'name' => '01 المراسلات', 'folder' => ['childCount' => 1], 'parentReference' => ['path' => $inside]],
            'file-id' => ['id' => 'file-id', 'name' => 'report.pdf', 'size' => 2048, 'file' => ['mimeType' => 'application/pdf'],
                'parentReference' => ['path' => $inside], '@microsoft.graph.downloadUrl' => 'https://download.od/report.pdf'],
            'inner-id' => ['id' => 'inner-id', 'name' => 'letter.docx', 'size' => 10, 'file' => [], 'parentReference' => ['path' => $inside.'/01%20المراسلات']],
            // Elsewhere in the same OneDrive.
            'outside-id' => ['id' => 'outside-id', 'name' => 'Private', 'folder' => ['childCount' => 0], 'parentReference' => ['path' => '/drive/root:/Work/Matters/123-2026-old']],
        ];
        $children = ['root-id' => ['file-id', 'sub-id'], 'sub-id' => ['inner-id'], 'outside-id' => []];

        Http::fake(function (Request $request) use ($items, $children) {
            $url = rawurldecode($request->url());

            if (str_contains($url, 'login.microsoftonline.com')) {
                return Http::response(['access_token' => 'token']);
            }

            if (str_starts_with($url, 'https://download.od/')) {
                return Http::response('%PDF-report');
            }

            // Found anywhere under the folder — and one elsewhere, never offered.
            if (str_contains($url, '/search(q=')) {
                return Http::response(['value' => [
                    $items['inner-id'],
                    ['id' => 'stray-id', 'name' => 'letter-old.docx', 'file' => [], 'parentReference' => ['path' => '/drive/root:/Work/Other']],
                ]]);
            }

            if ($request->method() !== 'GET') {
                $this->changes[] = ['method' => $request->method(), 'url' => $url, 'data' => $request->method() === 'PUT' ? [] : $request->data()];

                return Http::response(['id' => 'new-id', 'webUrl' => 'https://od/new'], $request->method() === 'DELETE' ? 204 : 200);
            }

            preg_match('#/items/([^/?:]+)(/children)?#', $url, $m);

            return isset($m[2])
                ? Http::response(['value' => array_map(fn ($id) => $items[$id] + ['webUrl' => 'https://od/'.$id], $children[$m[1]] ?? [])])
                : Http::response(($items[$m[1]] ?? []) + ['webUrl' => 'https://od/'.$m[1]]);
        });
    }

    private function page()
    {
        return Livewire::test(MatterOneDriveFiles::class, ['matter' => $this->matter]);
    }

    public function test_the_folder_is_browsed_folder_by_folder(): void
    {
        $page = $this->page()
            // Folders first.
            ->assertCanSeeTableRecords(['sub-id', 'file-id'], inOrder: true)
            ->assertSee('report.pdf')
            ->assertSee('2 KB');

        $page->callTableAction('open', 'sub-id')
            ->assertSet('trail', [['id' => 'sub-id', 'name' => '01 المراسلات']])
            ->assertCanSeeTableRecords(['inner-id'])
            ->assertCanNotSeeTableRecords(['file-id']);

        // Back by the path.
        $page->call('goTo', -1)->assertSet('trail', [])->assertCanSeeTableRecords(['sub-id', 'file-id']);
    }

    public function test_a_file_is_downloaded_by_its_short_lived_link(): void
    {
        $this->page()->callTableAction('download', 'file-id')->assertRedirect('https://download.od/report.pdf');
    }

    public function test_files_are_uploaded_and_folders_made_renamed_and_deleted_there(): void
    {
        Storage::fake('local');

        $this->page()
            ->callTableAction('open', 'sub-id')
            ->callAction('upload', ['files' => [UploadedFile::fake()->create('مذكرة.pdf', 5, 'application/pdf')]])
            ->callAction('newFolder', ['name' => 'من المدعي: 2026'])
            ->callTableAction('rename', 'inner-id', ['name' => 'letter-2.docx'])
            ->callTableAction('delete', 'inner-id')
            ->assertHasNoErrors();

        $this->assertSame(['PUT', 'POST', 'PATCH', 'DELETE'], array_column($this->changes, 'method'));
        // Into the folder open, under the name it came with.
        $this->assertStringContainsString('/items/sub-id:/مذكرة.pdf:/content', $this->changes[0]['url']);
        // A name OneDrive takes.
        $this->assertSame('من المدعي 2026', $this->changes[1]['data']['name']);
        $this->assertSame('letter-2.docx', $this->changes[2]['data']['name']);
        $this->assertStringEndsWith('/items/inner-id', $this->changes[3]['url']);
        $this->assertSame([], Storage::disk('local')->allFiles('onedrive-uploads'));
    }

    public function test_files_are_picked_from_onedrive_and_attached_to_a_letter(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $this->withoutDefer();
        config([
            'mail.mailers.smtp.transport' => 'array',
            'mail_senders.senders.iflas' => ['username' => 'iflas@jpa.ae', 'address' => 'iflas@jpa.ae', 'name' => 'JPA', 'password' => 'x', 'host' => 'mail.test', 'port' => 587, 'encryption' => 'tls'],
        ]);
        $this->app->instance(SentFolder::class, Mockery::spy(SentFolder::class));
        $sent = [];
        Event::listen(MessageSent::class, function (MessageSent $event) use (&$sent) {
            $sent[] = $event->message;
        });

        Letterhead::create(['name' => 'Main', 'is_default' => true, 'elements' => Letterhead::defaultElements()]);
        $letter = app(LetterIssuer::class)->issue(
            LetterTemplate::create(['name' => 'إشعار', 'slug' => 'notice', 'locale' => 'ar', 'category' => 'letter', 'subject' => 'إشعار', 'body' => '<p>نص.</p>']),
            $this->matter, [['name' => 'منى', 'role' => null, 'emails' => ['mona@example.com']]], [],
        );

        // At once: those at the top and one folder down; by search, any in the folder.
        $field = EmailSendFields::oneDriveFiles($this->matter);
        $this->assertSame(['report.pdf', 'letter.docx — 01 المراسلات'], array_values($field->getOptions()));
        $this->assertSame([$this->folder->id.'|inner-id'], array_keys($field->getSearchResults('letter')));

        Livewire::test(LettersRelationManager::class, ['ownerRecord' => $this->matter, 'pageClass' => ViewMatter::class])
            ->mountTableAction('email', $letter)
            ->setTableActionData(['sender' => 'iflas', 'onedrive_files' => [$this->folder->id.'|file-id']])
            ->assertMountedActionModalSee('report.pdf')
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();

        $files = collect($sent[0]->getAttachments())->mapWithKeys(fn ($part) => [$part->getFilename() => $part->getBody()]);
        $this->assertSame('%PDF-report', $files['report.pdf']);
        // Fetched for this send only.
        $this->assertSame([], Storage::disk('local')->allFiles('onedrive-attachments'));
    }

    public function test_a_request_takes_its_file_from_onedrive_instead_of_an_upload(): void
    {
        Storage::fake('public');
        Mail::fake();

        // Review report: a file required — one from OneDrive will do.
        Livewire::test(ViewMatter::class, ['record' => $this->matter->getRouteKey()])
            ->call('mountAction', 'add_request', [], ['recordKey' => (string) $this->matter->getKey(), 'schemaComponent' => 'infolist'])
            ->set('mountedActions.0.data.type', RequestType::REVIEW_REPORT->value)
            ->set('mountedActions.0.data.attachments', [])
            ->set('mountedActions.0.data.comment', 'Please review')
            // Neither an upload nor one from OneDrive: refused.
            ->call('callMountedAction')
            ->assertHasErrors(['mountedActions.0.data.attachments'])
            ->set('mountedActions.0.data.onedrive_files', [$this->folder->id.'|file-id'])
            ->call('callMountedAction')
            ->assertHasNoErrors();

        $attachment = $this->matter->requests()->sole()->attachments()->sole();
        $this->assertStringEndsWith('report.pdf', $attachment->path);
        // Kept as it was then, like an upload.
        $this->assertSame('%PDF-report', Storage::disk('public')->get($attachment->path));
        $this->assertSame('pdf', $attachment->extension);
    }

    public function test_a_onedrive_file_that_cannot_be_had_stops_the_send(): void
    {
        $this->expectException(Halt::class);

        try {
            EmailSendFields::uploaded(['onedrive_files' => [$this->folder->id.'|outside-id']], $this->matter);
        } finally {
            Notification::assertNotified(__('A file from OneDrive could not be attached'));
        }
    }

    public function test_nothing_outside_the_matters_folder_is_reached(): void
    {
        $explorer = app(MatterOneDriveExplorer::class);

        foreach ([
            fn () => $explorer->list($this->folder, 'outside-id'),
            fn () => $explorer->delete($this->folder, null, 'outside-id'),
            // Nor the matter's folder itself.
            fn () => $explorer->delete($this->folder, null, 'root-id'),
        ] as $try) {
            try {
                $try();
                $this->fail('Reached what it should not.');
            } catch (RuntimeException) {
            }
        }

        $this->assertSame([], $this->changes);

        // The path can't be set from the page.
        $this->expectException(\Exception::class);
        $this->page()->set('trail', [['id' => 'outside-id', 'name' => 'Private']]);
    }

    public function test_a_folder_not_yet_made_shows_how_it_is_going(): void
    {
        $this->folder->update(['status' => MatterOneDriveFolder::FAILED, 'error' => 'No OneDrive account']);
        $pending = MatterOneDriveFolder::create(['matter_id' => $this->matter->id, 'folder_name' => '123-2026', 'status' => MatterOneDriveFolder::PENDING,
            'party_id' => Party::factory()->assistant()->create(['name' => 'Nahla', 'onedrive_email' => 'nahla@firm.ae'])->id]);

        $page = $this->page()
            ->assertSee(__('Failed').' — No OneDrive account')
            ->assertTableActionHidden('upload');

        // Made meanwhile (Create folders): shown when asked.
        $pending->update(['status' => MatterOneDriveFolder::CREATED, 'drive_item_id' => 'root-id']);
        $page->dispatch('onedrive-folders-changed')
            ->assertSet('folderId', $pending->id)
            ->assertCanSeeTableRecords(['sub-id', 'file-id']);
    }

    public function test_an_assistant_sees_only_their_own_folder_and_cannot_change_it_without_the_right(): void
    {
        $this->allowAll = false;
        $user = User::factory()->create();

        $this->actingAs($user);
        $this->page()->assertSee(__('No folders yet.'));

        $this->folder->party->update(['user_id' => $user->id]);
        $this->actingAs($user->fresh());
        $this->page()
            ->assertCanSeeTableRecords(['file-id'])
            ->assertTableActionHidden('upload')
            ->assertTableActionHidden('delete', 'file-id');
    }
}
