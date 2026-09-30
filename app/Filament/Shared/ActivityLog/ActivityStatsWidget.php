<?php

namespace App\Filament\Shared\ActivityLog;

use AlizHarb\ActivityLog\Widgets\ActivityStatsWidget as BaseActivityStatsWidget;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;

/**
 * The activity-log plugin's widget, behind its own permission
 * (config/filament-activity-log.php lists this class in its place).
 */
class ActivityStatsWidget extends BaseActivityStatsWidget
{
    use HasWidgetShield;
}
