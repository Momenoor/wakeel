<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Support\Permissions;

class CalendarEventPermissionsSeeder extends Seeder
{
    private const PERMISSIONS = [
        'ViewAny:CalendarEvent',
        'View:CalendarEvent',
        'Create:CalendarEvent',
        'Update:CalendarEvent',
        'Delete:CalendarEvent',
        'Restore:CalendarEvent',
        'ForceDelete:CalendarEvent',
        'CreateSingle:CalendarEvent',
        'CreateBulk:CalendarEvent',
        'ImportFromOutlook:CalendarEvent',
        'SyncToOutlook:CalendarEvent',
    ];

    public function run(): void
    {
        Permissions::ensure(self::PERMISSIONS);
    }
}
