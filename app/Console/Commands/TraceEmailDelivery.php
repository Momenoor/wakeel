<?php

namespace App\Console\Commands;

use App\Services\MMS\MessageTrace;
use Illuminate\Console\Command;

/**
 * What became of the emails sent from matters, from Microsoft 365's message trace (MessageTrace) — every quarter of
 * an hour, by the scheduler, while it is on in Settings → Integrations → Microsoft 365.
 */
class TraceEmailDelivery extends Command
{
    protected $signature = 'mail:trace-delivery';

    protected $description = 'Read Microsoft 365\'s message trace for the emails sent from matters: delivered, pending, failed, quarantined or taken for spam, at each address';

    public function handle(MessageTrace $trace): int
    {
        if (! MessageTrace::enabled()) {
            $this->info(__('Following the delivery of sent emails is off (Settings → Integrations → Microsoft 365).'));

            return self::SUCCESS;
        }

        $result = $trace->run();
        $this->info(__(':checked emails looked for, :updated updated.', $result));

        return self::SUCCESS;
    }
}
