<?php

use App\Filament\Mms\Pages\PartyDuplicatesMaintenance;
use App\Support\Permissions;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Maintenance → Duplicate parties (PartyDuplicatesMaintenance): the admin role is given the screen; the super admin
 * has it as everything else.
 */
return new class extends Migration
{
    public function up(): void
    {
        Permissions::ensure([PartyDuplicatesMaintenance::PERMISSION]);

        if (($admin = Role::query()->where('name', 'admin')->where('guard_name', 'web')->first()) !== null) {
            $admin->givePermissionTo(Permission::findByName(PartyDuplicatesMaintenance::PERMISSION, 'web'));
        }
    }

    public function down(): void
    {
        Permission::query()->where('name', PartyDuplicatesMaintenance::PERMISSION)->delete();
    }
};
