<?php

namespace App\Console\Commands;

use App\Services\PMS\LeaseService;
use Illuminate\Console\Command;

class ExpireLeases extends Command
{
    protected $signature = 'pms:expire-leases';

    protected $description = 'Mark active leases past their last day as expired';

    public function handle(LeaseService $leases): int
    {
        $this->info(__('Leases expired: :count', ['count' => $leases->expireEnded()]));

        return self::SUCCESS;
    }
}
