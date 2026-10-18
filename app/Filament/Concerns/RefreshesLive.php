<?php

namespace App\Filament\Concerns;

use App\Support\Live;

/**
 * A dashboard widget kept current over Pusher: redrawn when what it counts changes (RecordsChanged)
 * and when the connection comes back after being lost — not polled every couple of minutes. Without
 * Pusher it polls as set ($pollingInterval).
 */
trait RefreshesLive
{
    protected function getPollingInterval(): ?string
    {
        return Live::viaPusher() ? null : $this->pollingInterval;
    }

    /**
     * @return array<string, string>
     */
    protected function getListeners(): array
    {
        return Live::viaPusher()
            ? [...parent::getListeners(), 'echo-private:records,.records.changed' => '$refresh', 'wakeel-reconnected' => '$refresh']
            : parent::getListeners();
    }
}
