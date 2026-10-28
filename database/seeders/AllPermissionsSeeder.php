<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Support\Permissions;
use BezhanSalleh\FilamentShield\Facades\FilamentShield;
use Illuminate\Database\Seeder;
use Spatie\Permission\PermissionRegistrar;

/**
 * Ensures all application, Shield, and policy permissions exist in the
 * database — no roles are created or granted here. `super_admin` bypasses
 * every permission check via Shield's own Gate::before (see
 * config/filament-shield.php `super_admin.define_via_gate`), so it needs no
 * explicit grants; any other role is created and assigned permissions by
 * the office through Shield's own Role resource, using these rows as the
 * assignable list.
 *
 * Idempotent — running multiple times safely creates only missing permissions.
 */
class AllPermissionsSeeder extends Seeder
{
    /**
     * Custom permissions that might not be automatically detected by Shield.
     * All permissions follow their resource, page, or widget directly.
     *
     * @var list<string>
     */
    private const ADDITIONAL_PERMISSIONS = [
        // Gates the Matters/Properties Management System switcher and direct
        // access to the `pms` panel — separate from the individual PMS
        // resource permissions (View:Property, etc.), which still control
        // which PMS resources a role sees once inside that panel.
        'Access:MultipleSystems',
    ];

    public function run(): void
    {
        // 1. Clear permission cache before querying/modifying
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // 2. Discover all permissions from Shield (Resources, Pages, Widgets, Custom)
        $shieldPermissions = FilamentShield::getEntitiesPermissions();

        // 3. Combine with additional permissions
        $allPermissions = collect([...$shieldPermissions, ...self::ADDITIONAL_PERMISSIONS])
            ->unique()
            ->filter()
            ->values();

        // 4. Read once, the missing written at once; the cache cleared so they take effect immediately.
        $createdCount = Permissions::ensure($allPermissions);

        $this->command?->info("Processed {$allPermissions->count()} total permissions ({$createdCount} newly created).");

        $this->command?->info('✓ All permissions seeded and cache cleared successfully.');
    }
}
