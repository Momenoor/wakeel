<?php

namespace App\Filament\Shared\ActivityLog;

use AlizHarb\ActivityLog\Pages\AuditDashboard as BaseAuditDashboard;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;

/**
 * The activity-log plugin's Audit Dashboard, behind its own permission —
 * the plugin's page has no access check at all, so anyone signed in could
 * open it by URL and see everyone's activity. Registered in place of it
 * (the plugin's own dashboard is turned off in the panel providers).
 */
class AuditDashboard extends BaseAuditDashboard
{
    use HasPageShield;

    protected function getHeaderWidgets(): array
    {
        return [
            ActivityStatsWidget::class,
            ActivityChartWidget::class,
            ActivityHeatmapWidget::class,
        ];
    }
}
