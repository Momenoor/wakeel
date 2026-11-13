<?php

namespace App\Filament\Concerns;

use App\Support\Live;

/**
 * A dashboard widget (a count, a chart, a table, a calendar) kept current over Pusher: redrawn when what it counts changes (RecordsChanged)
 * and when the connection comes back after being lost — not polled every couple of minutes. Without
 * Pusher it polls as set ($pollingInterval).
 */
trait RefreshesLive
{
    protected function getPollingInterval(): ?string
    {
        return Live::viaPusher() ? null : ($this->pollingInterval ?? null);
    }

    /**
     * @return array<string, string>
     */
    protected function getListeners(): array
    {
        return Live::viaPusher()
            ? [...parent::getListeners(), 'echo-private:records,.records.changed' => $this->liveRefreshMethod(), 'wakeel-reconnected' => $this->liveRefreshMethod()]
            : parent::getListeners();
    }

    /** What reads the widget again: a redraw, or a method of its own (a calendar fetching its events). */
    protected function liveRefreshMethod(): string
    {
        return '$refresh';
    }
}
