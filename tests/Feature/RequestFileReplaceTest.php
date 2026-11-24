<?php

namespace Tests\Feature;

use App\Enums\AttachmentCategory;
use App\Enums\MatterDifficulty;
use App\Enums\RequestStatus;
use App\Enums\RequestType;
use App\Filament\Mms\Actions\Request\ReplaceRequestFileAction;
use App\Filament\Mms\Resources\MatterRequests\Pages\ViewMatterRequest;
use App\Filament\Support\AttachmentFields;
use App\Models\Attachment;
use App\Models\Matter;
use App\Models\MatterRequest;
use App\Models\User;
use App\Services\Attachments\AttachmentService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * A request's file replaced by the super admin — at the same step of the request, the one replaced deleted —
 * and by nobody else.
 */
class RequestFileReplaceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private MatterRequest $request;

    private Attachment $file;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Filament::setCurrentPanel(Filament::getPanel('mms'));
        Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        $this->admin = User::factory()->create()->assignRole('super-admin');
        $this->actingAs($this->admin);
        $matter = Matter::create(['number' => '1', 'year' => '2026', 'difficulty' => MatterDifficulty::EASY]);
        $this->request = MatterRequest::create(['matter_id' => $matter->id, 'request_by' => $this->admin->id, 'type' => RequestType::REVIEW_REPORT, 'status' => RequestStatus::PENDING, 'comment' => 'x']);

        $this->travel(-3)->days();
        $this->file = app(AttachmentService::class)->store($this->request, 'old report', AttachmentCategory::REQUEST, 'report.docx', $this->admin);
        $this->travelBack();
    }

    private function upload(string $name, string $contents): string
    {
        $path = AttachmentFields::DIRECTORY.'/up/'.$name;
        Storage::disk('local')->put($path, $contents);

        return $path;
    }

    public function test_the_file_put_in_its_place_keeps_its_step_and_the_old_one_is_deleted(): void
    {
        $new = ReplaceRequestFileAction::replace($this->file, $this->upload('report-final.docx', 'new report'));

        $this->assertNotNull($new);
        $this->assertSame('report-final.docx', $new->name);
        $this->assertSame(AttachmentCategory::REQUEST, $new->category);
        // Its date — which says the step of the request — is the one it replaces'.
        $this->assertSame($this->file->created_at->toDateTimeString(), $new->fresh()->created_at->toDateTimeString());
        $this->assertSame('new report', $new->storage()->get($new->path));

        // The old one deleted the way a request's files are: for good (only financial evidence is kept hidden).
        $this->assertSame([$new->id], $this->request->attachments()->pluck('id')->all());
        $this->assertNull(Attachment::withTrashed()->find($this->file->id));
        $this->assertFalse($this->file->storage()->exists($this->file->path));
    }

    public function test_a_kind_not_allowed_is_refused_and_the_file_stays(): void
    {
        $this->assertNull(ReplaceRequestFileAction::replace($this->file, $this->upload('run.exe', 'MZ')));

        $this->assertSame([$this->file->id], $this->request->attachments()->pluck('id')->all());
    }

    public function test_only_the_super_admin_sees_replace(): void
    {
        Livewire::test(ViewMatterRequest::class, ['record' => $this->request->getKey()])
            ->assertSee(__('Replace this file'));

        $manager = User::factory()->create();
        $manager->givePermissionTo(collect(['View:MatterRequest', 'ViewAny:MatterRequest', 'View:Matter', 'ViewAny:Matter'])
            ->map(fn (string $name) => Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web'])));

        Livewire::actingAs($manager)
            ->test(ViewMatterRequest::class, ['record' => $this->request->getKey()])
            ->assertDontSee(__('Replace this file'));
    }
}
