<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Matter;
use App\Models\User;
use App\Support\FileName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * A matter's attachment downloaded under the name it was saved with.
 */
class AttachmentDownloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_name_with_a_slash_still_downloads(): void
    {
        // Saved as "محضر 1 — 571/2025 — 30/09/2026.pdf": the download was
        // refused ("The filename and the fallback cannot contain the "/"…").
        Storage::fake('public');
        Storage::disk('public')->put('attachments/minutes.pdf', '%PDF-1.4');

        $user = User::factory()->create();
        $user->givePermissionTo(Permission::firstOrCreate(['name' => 'View:Matter', 'guard_name' => 'web']));

        $attachment = Attachment::create([
            'matter_id' => Matter::factory()->create()->id,
            'user_id' => $user->id,
            'name' => 'محضر 1 — 571/2025 — 30/09/2026.pdf',
            'path' => 'attachments/minutes.pdf',
            'size' => '8',
            'extension' => 'pdf',
        ]);

        $response = $this->actingAs($user->fresh())->get(route('attachment.download', $attachment));

        $response->assertOk();
        $this->assertStringContainsString(rawurlencode('محضر 1 — 571 2025 — 30 09 2026.pdf'), (string) $response->headers->get('Content-Disposition'));
    }

    public function test_safe_names_drop_what_a_file_name_cannot_hold(): void
    {
        $this->assertSame('a b c d.pdf', FileName::safe(" a/b\\c:\t d.pdf "));
        $this->assertSame('letter', FileName::safe('///', 'letter'));
    }
}
