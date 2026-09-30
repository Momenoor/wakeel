<?php

namespace App\Filament\Shared\ActivityLog;

use AlizHarb\ActivityLog\Widgets\ActivityHeatmapWidget as BaseActivityHeatmapWidget;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;

/**
 * The activity-log plugin's widget, behind its own permission
 * (config/filament-activity-log.php lists this class in its place).
 */
class ActivityHeatmapWidget extends BaseActivityHeatmapWidget
{
    use HasWidgetShield;
}
