<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Support\Permissions;

class IncentiveCalculationPermissionsSeeder extends Seeder
{
    private const PERMISSIONS = [
        'RunCalculation:IncentiveCalculation',
        'Finalize:IncentiveCalculation',
        'Print:IncentiveCalculation',
    ];

    public function run(): void
    {
        Permissions::ensure(self::PERMISSIONS);
    }
}
