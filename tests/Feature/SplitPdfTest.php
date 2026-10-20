<?php

namespace Tests\Feature;

use App\Filament\Mms\Pages\SplitPdf;
use App\Models\Party;
use App\Models\User;
use App\Support\ScreenPermissions;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Splitting a PDF: the Split PDF page for those allowed; the user's own OneDrive browsed for folders and PDFs
 * only, a PDF of theirs passed on from it as it comes, and nothing of theirs anyone else's; nothing kept of a
 * split but a line in the activity log.
 */
class SplitPdfTest extends TestCase
{
    use RefreshDatabase;

    private const MAILBOX = 'amr@firm.ae';

    /** @var list<string> every Graph address asked for */
    private array $asked = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Filament::setCurrentPanel('mms');
        config(['mail.mailers.microsoft-graph' => ['transport' => 'microsoft-graph', 'tenant_id' => 'tenant', 'client_id' => 'client', 'client_secret' => 'secret']]);

        $drive = '/users/'.rawurlencode(self::MAILBOX).'/drive';
        $items = [
            'root' => ['value' => [
                ['id' => 'report-id', 'name' => 'Report.PDF', 'size' => 2048, 'file' => ['mimeType' => 'application/pdf']],
                ['id' => 'letter-id', 'name' => 'Letter.docx', 'size' => 10, 'file' => ['mimeType' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document']],
                ['id' => 'scan-id', 'name' => 'scan', 'size' => 99, 'file' => ['mimeType' => 'application/pdf']],
                ['id' => 'court-id', 'name' => 'Court', 'folder' => ['childCount' => 1]],
                ['id' => 'archive-id', 'name' => 'archive', 'folder' => ['childCount' => 0]],
            ]],
            'court-id' => ['value' => [['id' => 'judgment-id', 'name' => 'حكم.pdf', 'size' => 5000, 'file' => ['mimeType' => 'application/pdf']]]],
        ];
        $files = [
            'report-id' => ['id' => 'report-id', 'name' => 'Report.PDF', 'size' => 2048, 'file' => ['mimeType' => 'application/pdf'], '@microsoft.graph.downloadUrl' => 'https://download.od/report'],
            'letter-id' => ['id' => 'letter-id', 'name' => 'Letter.docx', 'size' => 10, 'file' => ['mimeType' => 'application/msword'], '@microsoft.graph.downloadUrl' => 'https://download.od/letter'],
            'fake-id' => ['id' => 'fake-id', 'name' => 'Fake.pdf', 'size' => 10, 'file' => ['mimeType' => 'application/pdf'], '@microsoft.graph.downloadUrl' => 'https://download.od/fake'],
            'court-id' => ['id' => 'court-id', 'name' => 'Court', 'folder' => ['childCount' => 1]],
        ];

        Http::fake(function (Request $request) use ($drive, $items, $files) {
            $url = rawurldecode($request->url());
            $this->asked[] = $request->url();

            if (str_contains($url, 'login.microsoftonline.com')) {
                return Http::response(['access_token' => 'token']);
            }

            if (str_starts_with($url, 'https://download.od/')) {
                return match ($url) {
                    'https://download.od/report' => Http::response('%PDF-1.7 '.str_repeat('x', 3 * 1024 * 1024).' %%EOF'),
                    default => Http::response('<html>not a pdf</html>'),
                };
            }

            // Anyone else's drive: not there for this app's questions in these tests.
            if (! str_starts_with($url, 'https://graph.microsoft.com/v1.0'.rawurldecode($drive).'/')) {
                return Http::response(['error' => ['code' => 'ResourceNotFound', 'message' => 'User not found']], 404);
            }

            if (str_contains($url, '/search(q=')) {
                return Http::response(['value' => [
                    ['id' => 'judgment-id', 'name' => 'حكم.pdf', 'size' => 5000, 'file' => [], 'parentReference' => ['path' => '/drive/root:/Court/2026']],
                    ['id' => 'letter-id', 'name' => 'Letter.docx', 'size' => 10, 'file' => [], 'parentReference' => ['path' => '/drive/root:']],
                    ['id' => 'court-id', 'name' => 'Court', 'folder' => ['childCount' => 1], 'parentReference' => ['path' => '/drive/root:']],
                ]]);
            }

            if (preg_match('#/items/([^/?]+)/children#', $url, $m)) {
                return isset($items[$m[1]]) ? Http::response($items[$m[1]]) : Http::response(['error' => ['code' => 'itemNotFound', 'message' => 'Item not found']], 404);
            }

            if (preg_match('#/items/([^/?]+)$#', $url, $m)) {
                return isset($files[$m[1]]) ? Http::response($files[$m[1]]) : Http::response(['error' => ['code' => 'itemNotFound', 'message' => 'Item not found']], 404);
            }

            return Http::response(['error' => ['message' => 'Unexpected']], 500);
        });
    }

    /** @param  list<string>  $permissions */
    private function userWith(array $permissions = [ScreenPermissions::SPLIT_PDF], ?string $oneDrive = self::MAILBOX): User
    {
        $user = User::factory()->create(['email' => 'user'.(User::query()->max('id') + 1).'@example.com']);
        Party::factory()->create(['user_id' => $user->getKey(), 'onedrive_email' => $oneDrive]);

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $user->givePermissionTo($permissions);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    public function test_the_page_opens_only_for_those_who_may_split(): void
    {
        $this->actingAs($this->userWith([]));
        $this->get(SplitPdf::getUrl())->assertForbidden();
        $this->assertFalse(SplitPdf::canAccess());

        $this->actingAs($this->userWith());
        $this->get(SplitPdf::getUrl())->assertOk()->assertSee('pdfSplitter', false)->assertSee(__('From OneDrive'));
        $this->assertTrue(SplitPdf::canAccess());
    }

    public function test_their_own_onedrive_is_browsed_for_folders_and_pdfs_only(): void
    {
        $this->actingAs($this->userWith());

        $top = Livewire::test(SplitPdf::class)->instance()->oneDriveFolder();

        $this->assertNull($top['error']);
        // Folders first, then PDFs (by type or by name) — never the Word file.
        $this->assertSame(['archive-id', 'court-id', 'report-id', 'scan-id'], array_column($top['items'], 'id'));
        $this->assertSame([true, true, false, false], array_column($top['items'], 'folder'));
        $this->assertSame(2048, $top['items'][2]['size']);

        $inside = Livewire::test(SplitPdf::class)->instance()->oneDriveFolder('court-id');
        $this->assertSame([['id' => 'judgment-id', 'name' => 'حكم.pdf', 'folder' => false, 'size' => 5000, 'where' => null]], $inside['items']);

        // Only ever their own drive (their party's Microsoft account, not their login email).
        $graph = array_filter($this->asked, fn (string $url): bool => str_contains($url, 'graph.microsoft.com'));
        $this->assertNotEmpty($graph);
        foreach ($graph as $url) {
            $this->assertStringContainsString('/users/'.rawurlencode(self::MAILBOX).'/drive/', $url);
        }
    }

    public function test_searching_finds_folders_and_pdfs_and_where_they_are(): void
    {
        $this->actingAs($this->userWith());

        $found = Livewire::test(SplitPdf::class)->instance()->oneDriveSearch('حكم');

        $this->assertSame(['court-id', 'judgment-id'], array_column($found['items'], 'id'));
        $this->assertSame('Court/2026', $found['items'][1]['where']);
        // Too short to search for: nothing asked.
        $this->assertSame(['items' => [], 'error' => null], Livewire::test(SplitPdf::class)->instance()->oneDriveSearch('a'));
    }

    public function test_a_folder_not_in_their_drive_and_a_missing_onedrive_are_told_not_listed(): void
    {
        $this->actingAs($this->userWith());
        $other = Livewire::test(SplitPdf::class)->instance()->oneDriveFolder('someone-elses-id');
        $this->assertSame([], $other['items']);
        $this->assertStringContainsString(__('Your OneDrive could not be read: :error', ['error' => '']), $other['error']);

        // An id that is not one is not even asked about.
        $count = count($this->asked);
        $bad = Livewire::test(SplitPdf::class)->instance()->oneDriveFolder('../../users/boss@firm.ae/drive/root');
        $this->assertSame([], $bad['items']);
        $this->assertCount($count, $this->asked);

        // Their login email stands in when their profile has no Microsoft account.
        $this->actingAs($withoutOneDrive = $this->userWith(oneDrive: null));
        $none = Livewire::test(SplitPdf::class)->instance()->oneDriveFolder();
        $this->assertStringContainsString('/users/'.rawurlencode($withoutOneDrive->email).'/drive/', (string) end($this->asked));
        // That account has no OneDrive: told so, not logged as a fault.
        $this->assertSame(__('Your account has no OneDrive yet. Open OneDrive once with it (office.com), then try again.'), $none['error']);

        config(['mail.mailers.microsoft-graph' => [], 'services.outlook' => []]);
        $this->assertSame(['items' => [], 'error' => __('OneDrive is not set up in this system yet.')], Livewire::test(SplitPdf::class)->instance()->oneDriveFolder());
    }

    public function test_a_pdf_of_theirs_is_passed_on_as_it_comes(): void
    {
        $this->actingAs($this->userWith());

        $response = $this->get(route('split-pdf.onedrive', ['item' => 'report-id']));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertSame('2048', $response->headers->get('X-File-Size'));
        $body = $response->streamedContent();
        $this->assertStringStartsWith('%PDF-1.7 ', $body);
        $this->assertSame(strlen('%PDF-1.7 ') + 3 * 1024 * 1024 + strlen(' %%EOF'), strlen($body));
        // Nothing kept on the server.
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_the_onedrive_route_refuses_what_is_not_theirs_not_a_pdf_or_not_allowed(): void
    {
        $this->get(route('split-pdf.onedrive', ['item' => 'report-id']))->assertRedirect();

        $this->actingAs($this->userWith([]));
        $this->get(route('split-pdf.onedrive', ['item' => 'report-id']))->assertForbidden();

        $this->actingAs($this->userWith());
        // Not in their drive.
        $this->get(route('split-pdf.onedrive', ['item' => 'someone-elses-id']))->assertNotFound();
        // A folder, a Word file, and a "PDF" whose contents are not one.
        $this->get(route('split-pdf.onedrive', ['item' => 'court-id']))->assertForbidden();
        $this->get(route('split-pdf.onedrive', ['item' => 'letter-id']))->assertForbidden();
        $this->get(route('split-pdf.onedrive', ['item' => 'fake-id']))->assertForbidden();
        // Never an address: only an id is taken.
        $this->get('/split-pdf/onedrive/'.rawurlencode('https://evil.example/x.pdf'))->assertNotFound();

        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'download.od/letter') || str_contains($request->url(), 'evil.example'));
    }

    public function test_a_split_leaves_only_a_line_in_the_activity_log(): void
    {
        $this->actingAs($me = $this->userWith());

        Livewire::test(SplitPdf::class)->call('recordSplit', 'Bundle.pdf', 'onedrive', 'size', 5, 12, 480);
        Livewire::test(SplitPdf::class)->call('recordSplit', 'Other.pdf', 'whatever', 'pages', 10.7, 3, 25);

        [$bySize, $byPages] = Activity::query()->where('log_name', SplitPdf::LOG)->orderBy('id')->get()->all();
        $this->assertSame($me->getKey(), (int) $bySize->causer_id);
        $this->assertSame('split', $bySize->event);
        $this->assertSame(['file' => 'Bundle.pdf', 'source' => 'onedrive', 'mode' => 'size', 'limit' => 5, 'parts' => 12, 'pages' => 480], $bySize->properties->all());
        $this->assertSame(['file' => 'Other.pdf', 'source' => 'upload', 'mode' => 'pages', 'limit' => 10, 'parts' => 3, 'pages' => 25], $byPages->properties->all());
        $this->assertNull($bySize->subject_id);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_the_migration_grants_the_page_to_those_who_add_files_to_matters_and_the_admin_role(): void
    {
        $clerk = Role::create(['name' => 'clerk', 'guard_name' => 'web']);
        $clerk->givePermissionTo(Permission::findOrCreate('CreateAttachment:Matter', 'web'));
        $viewer = Role::create(['name' => 'viewer', 'guard_name' => 'web']);
        $viewer->givePermissionTo(Permission::findOrCreate('View:Matter', 'web'));
        $admin = Role::create(['name' => 'admin', 'guard_name' => 'web']);

        DB::table('permissions')->where('name', ScreenPermissions::SPLIT_PDF)->delete();
        (require database_path('migrations/2020_01_01_000177_grant_split_pdf_permission.php'))->up();

        $this->assertTrue($clerk->fresh()->hasPermissionTo(ScreenPermissions::SPLIT_PDF));
        $this->assertTrue($admin->fresh()->hasPermissionTo(ScreenPermissions::SPLIT_PDF));
        $this->assertFalse($viewer->fresh()->hasPermissionTo(ScreenPermissions::SPLIT_PDF));
        $this->assertContains(ScreenPermissions::SPLIT_PDF, config('filament-shield.custom_permissions'));
    }
}
