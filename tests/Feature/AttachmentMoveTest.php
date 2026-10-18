<?php

namespace Tests\Feature;

use App\Mail\NewRequestNotificationMail;
use App\Mail\RequestActionNotificationMail;
use App\Models\Attachment;
use App\Models\Matter;
use App\Models\MatterEmail;
use App\Models\MatterRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * `php artisan attachments:move`: the files attached before the private disk existed go to it — copied,
 * verified, the row pointed at the copy, the public one deleted — and their old /storage addresses keep
 * opening them for whoever may see them.
 */
class AttachmentMoveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('attachments');
        Storage::fake('public');
    }

    /** A file as it was attached before: on the public disk, with its row. */
    private function oldFile(string $path, string $contents = '%PDF-old', ?Matter $matter = null, array $row = []): Attachment
    {
        Storage::disk('public')->put($path, $contents);

        return ($matter ?? Matter::factory()->create())->attachments()->create(array_merge([
            'user_id' => User::factory()->create()->id,
            'name' => basename($path),
            'path' => $path,
            'size' => (string) strlen($contents),
            'extension' => pathinfo($path, PATHINFO_EXTENSION),
            'type' => 'correspondence',
        ], $row));
    }

    public function test_a_dry_run_reports_and_changes_nothing(): void
    {
        $file = $this->oldFile('attachments/emails/1/old.pdf');

        $this->artisan('attachments:move', ['--dry-run' => true])->assertExitCode(0);

        $file->refresh();
        $this->assertSame(['public', 'attachments/emails/1/old.pdf', null], [$file->disk, $file->path, $file->legacy_path]);
        Storage::disk('public')->assertExists('attachments/emails/1/old.pdf');
        $this->assertSame([], Storage::disk('attachments')->allFiles());
    }

    public function test_a_file_is_copied_verified_pointed_at_and_its_public_copy_removed(): void
    {
        $matter = Matter::factory()->create();
        $user = User::factory()->create();
        $request = MatterRequest::create(['matter_id' => $matter->id, 'request_by' => $user->id, 'type' => 'review_report', 'status' => 'pending', 'comment' => 'x']);

        $onMatter = $this->oldFile('attachments/minutes/5/signed/1-abc.pdf', '%PDF-signed', $matter);
        $onRequest = $this->oldFile('requests-attachments/xyz-Report نهائي.pdf', 'report bytes', $matter, ['matter_request_id' => $request->id, 'type' => 'matter-request']);

        $this->artisan('attachments:move')->assertExitCode(0);

        foreach ([$onMatter, $onRequest] as $file) {
            $legacy = $file->path;
            $file->refresh();

            $this->assertSame('attachments', $file->disk);
            $this->assertSame($legacy, $file->legacy_path === $legacy ? $legacy : null);
            Storage::disk('attachments')->assertExists($file->path);
            Storage::disk('public')->assertMissing($file->legacy_path);
            // Where it is says nothing of what it is.
            $this->assertMatchesRegularExpression('~^(matter|matter_request)/\d{4}/\d+/[0-9a-f-]{36}\.pdf$~', $file->path);
        }

        $onMatter->refresh();
        $onRequest->refresh();
        $this->assertSame("matter/{$onMatter->created_at->format('Y')}/{$matter->id}", dirname($onMatter->path));
        $this->assertSame("matter_request/{$onRequest->created_at->format('Y')}/{$request->id}", dirname($onRequest->path));
        $this->assertSame('attachments/minutes/5/signed/1-abc.pdf', $onMatter->legacy_path);
        $this->assertSame('requests-attachments/xyz-Report نهائي.pdf', $onRequest->legacy_path);

        // Its checksum, size and type are recorded; the copy is the original.
        $this->assertSame([hash('sha256', '%PDF-signed'), 11], [$onMatter->checksum, $onMatter->size_bytes]);
        $this->assertNotNull($onMatter->mime);
        $this->assertSame('report bytes', Storage::disk('attachments')->get($onRequest->path));
        // Opened by its own model from the new place.
        $this->assertTrue($onRequest->fileExists());
    }

    public function test_a_file_that_is_not_there_is_counted_not_fatal_and_left_alone(): void
    {
        $gone = $this->oldFile('attachments/emails/1/gone.pdf');
        $here = $this->oldFile('attachments/emails/1/here.pdf', 'here');
        Storage::disk('public')->delete($gone->path);

        $this->artisan('attachments:move')->assertExitCode(0);

        $this->assertSame('public', $gone->refresh()->disk);
        $this->assertNull($gone->legacy_path);
        $this->assertSame('attachments', $here->refresh()->disk);
    }

    public function test_a_second_run_does_nothing(): void
    {
        $file = $this->oldFile('attachments/emails/1/old.pdf');

        $this->artisan('attachments:move')->assertExitCode(0);
        $moved = $file->refresh()->only(['disk', 'path', 'legacy_path']);
        $files = Storage::disk('attachments')->allFiles();

        $this->artisan('attachments:move')->assertExitCode(0);

        $this->assertSame($moved, $file->refresh()->only(['disk', 'path', 'legacy_path']));
        $this->assertSame($files, Storage::disk('attachments')->allFiles());
    }

    public function test_the_limit_moves_that_many_and_the_next_run_goes_on(): void
    {
        foreach (range(1, 3) as $i) {
            $this->oldFile("attachments/emails/1/f$i.pdf", "bytes $i");
        }

        $this->artisan('attachments:move', ['--limit' => 2, '--chunk' => 1])->assertExitCode(0);
        $this->assertSame(2, Attachment::where('disk', 'attachments')->count());

        $this->artisan('attachments:move', ['--limit' => 2])->assertExitCode(0);
        $this->assertSame(3, Attachment::where('disk', 'attachments')->count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_a_file_that_is_not_the_one_recorded_is_not_moved_and_the_exit_code_says_so(): void
    {
        $bad = $this->oldFile('attachments/emails/1/bad.pdf', 'changed since', null, ['checksum' => hash('sha256', 'what was recorded')]);
        $good = $this->oldFile('attachments/emails/1/good.pdf', 'fine');

        $this->artisan('attachments:move')->assertExitCode(1);

        // The failed one is untouched, and the others still went.
        $this->assertSame('public', $bad->refresh()->disk);
        Storage::disk('public')->assertExists($bad->path);
        $this->assertSame([], array_values(array_filter(Storage::disk('attachments')->allFiles(), fn ($path) => str_contains($path, 'bad'))));
        $this->assertSame('attachments', $good->refresh()->disk);
    }

    public function test_a_file_an_email_lists_stays_on_the_public_disk(): void
    {
        $matter = Matter::factory()->create();
        $kept = $this->oldFile('attachments/emails/1/20260101-120000-abc123.pdf', 'email pdf', $matter);
        MatterEmail::create([
            'matter_id' => $matter->id, 'direction' => 'received', 'status' => 'filed', 'sender_key' => 'x', 'at' => now(),
            'files' => [['name' => 'email.pdf', 'path' => $kept->path]],
        ]);

        $this->artisan('attachments:move')->assertExitCode(0);

        $this->assertSame('public', $kept->refresh()->disk);
        Storage::disk('public')->assertExists($kept->path);
    }

    public function test_two_rows_on_one_public_file_both_move_and_the_file_goes_with_the_last(): void
    {
        $matter = Matter::factory()->create();
        $first = $this->oldFile('attachments/emails/1/shared.pdf', 'shared bytes', $matter);
        $second = $this->oldFile('attachments/emails/1/shared.pdf', 'shared bytes', $matter);

        $this->artisan('attachments:move')->assertExitCode(0);

        foreach ([$first, $second] as $file) {
            $this->assertSame('shared bytes', Storage::disk('attachments')->get($file->refresh()->path));
        }
        Storage::disk('public')->assertMissing('attachments/emails/1/shared.pdf');
    }

    public function test_the_old_address_of_a_moved_file_sends_whoever_may_see_it_to_the_new_one(): void
    {
        $file = $this->oldFile('attachments/emails/9/old file.pdf');
        $this->artisan('attachments:move')->assertExitCode(0);
        $file->refresh();

        // Not signed in: to sign in. Signed in but not allowed: refused.
        $this->get('/storage/attachments/emails/9/old%20file.pdf')->assertRedirect();
        $stranger = User::factory()->create();
        $this->actingAs($stranger)->get('/storage/attachments/emails/9/old%20file.pdf')->assertForbidden();

        Gate::before(fn () => true);
        $this->get('/storage/attachments/emails/9/old%20file.pdf')->assertRedirect($file->url());
        // The file itself then opens, from the private disk.
        $this->get($file->url())->assertOk();

        // Unknown, or the file's new (private) path: nothing.
        $this->get('/storage/attachments/emails/9/other.pdf')->assertNotFound();
        $this->get('/storage/'.$file->path)->assertNotFound();
    }

    public function test_links_to_attachments_go_through_the_controller_and_read_from_their_own_disk(): void
    {
        $matter = Matter::factory()->create();
        $user = User::factory()->create();
        $request = MatterRequest::create(['matter_id' => $matter->id, 'request_by' => $user->id, 'type' => 'review_report', 'status' => 'pending', 'comment' => 'x']);
        $old = $this->oldFile('requests-attachments/a-report.pdf', 'report', $matter, ['matter_request_id' => $request->id, 'type' => 'matter-request']);
        $moved = $this->oldFile('requests-attachments/b-report.pdf', 'report 2', $matter, ['matter_request_id' => $request->id, 'type' => 'matter-request']);
        $this->artisan('attachments:move', ['--limit' => 1])->assertExitCode(0);

        // The email's links: the controller's address, never a public one.
        $html = view('mails.partials.request-attachments', ['matterRequest' => $request->fresh()])->render();
        $this->assertStringContainsString(e($old->url()), $html);
        $this->assertStringContainsString(e($moved->url()), $html);
        $this->assertStringNotContainsString('/storage/', $html);

        // Its files go with the email from wherever each is kept (one moved, one not yet).
        $this->assertCount(2, (new NewRequestNotificationMail($matter, $request->fresh()))->attachments());
        $this->assertCount(2, (new RequestActionNotificationMail($matter, $request->fresh(), 'Approved'))->attachments());
        $this->assertSame(1, Attachment::where('disk', 'attachments')->count());
    }
}
