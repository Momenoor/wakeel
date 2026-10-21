<?php

namespace Tests\Feature;

use App\Services\MMS\OneDriveClient;
use Tests\Support\FakeOneDrive;
use Tests\TestCase;

/**
 * A file put in OneDrive: a small one in one request, a bigger one in parts — each part tried again when
 * the line drops — so a long upload from a shared host is not lost (an email's 2.6 MB attachment was).
 */
class OneDriveUploadTest extends TestCase
{
    private FakeOneDrive $drive;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.outlook' => ['tenant_id' => 'tenant', 'client_id' => 'client', 'client_secret' => 'secret']]);
        $this->drive = (new FakeOneDrive)->install();
    }

    public function test_a_bigger_file_goes_in_parts_and_a_dropped_part_is_sent_again(): void
    {
        $folder = $this->drive->ensure('Matters/2026-1010');
        $this->drive->failParts = 1;

        $item = app(OneDriveClient::class)->upload('amr@firm.ae', $folder, 'وكالة فاخرة الرميثي.pdf', str_repeat('x', 3 * 1024 * 1024));

        // In 1.25 MB parts, the first sent again after it failed; the file there whole.
        $this->assertSame(['bytes 0-1310719/3145728', 'bytes 1310720-2621439/3145728', 'bytes 2621440-3145727/3145728'], $this->drive->parts);
        $this->assertNotSame('', $item['id']);
        $this->assertSame(['وكالة فاخرة الرميثي.pdf'], $this->drive->names('Matters/2026-1010'));
    }

    public function test_a_small_file_goes_in_one_request(): void
    {
        $folder = $this->drive->ensure('Matters/2026-1010');

        app(OneDriveClient::class)->upload('amr@firm.ae', $folder, 'الهوية.pdf', '%PDF-small');

        $this->assertSame([], $this->drive->parts);
        $this->assertSame(['الهوية.pdf'], $this->drive->names('Matters/2026-1010'));
    }
}
