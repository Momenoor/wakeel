<?php

namespace Tests\Feature;

use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The permissions an update makes sure of (Permissions): read once and the missing written at once — an update
 * asked for each of nearly a thousand by itself — and each panel's own, not the first panel's again.
 */
class EnsurePermissionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_missing_are_written_at_once_and_none_twice(): void
    {
        Permission::findOrCreate('View:Matter', 'web');

        DB::enableQueryLog();
        $added = Permissions::ensure(['View:Matter', 'Create:Matter', 'Update:Matter', 'Create:Matter']);
        $queries = count(DB::getQueryLog());

        $this->assertSame(2, $added);
        $this->assertLessThanOrEqual(3, $queries);
        $this->assertSame(1, Permission::where('name', 'View:Matter')->count());
        $this->assertSame(0, Permissions::ensure(['View:Matter', 'Create:Matter']));
    }

    public function test_each_panels_own_are_listed(): void
    {
        $mms = Permissions::ofPanels(['mms']);
        $both = Permissions::ofPanels(['mms', 'pms']);

        $this->assertContains('ViewAny:Matter', $mms);
        $this->assertNotEmpty(array_diff($both, $mms), 'the PMS panel\'s own were missing');
    }
}
