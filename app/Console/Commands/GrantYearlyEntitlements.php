<?php

namespace App\Console\Commands;

use App\Services\MMS\FlightTicketService;
use App\Services\MMS\LeaveBalanceService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * The yearly grants, due from 1 January: the annual leave days and each
 * entitled employee's flight ticket — plus the pro-rated share for anyone
 * who joined this year. Runs daily (bootstrap/app.php) and gives each grant
 * once, so a missed 1 January is made up the next day.
 */
class GrantYearlyEntitlements extends Command
{
    protected $signature = 'payroll:grant-yearly {--date= : Grant as of this date instead of today (Y-m-d)}';

    protected $description = 'Give the yearly annual-leave days and flight tickets that are due';

    public function handle(LeaveBalanceService $balances, FlightTicketService $tickets): int
    {
        $today = $this->option('date') ? Carbon::parse($this->option('date')) : now();

        $leave = $balances->grantDue($today);
        $created = $tickets->createDue($today);

        $this->info("Leave grants given: {$leave}. Flight tickets created: {$created}.");

        return self::SUCCESS;
    }
}
