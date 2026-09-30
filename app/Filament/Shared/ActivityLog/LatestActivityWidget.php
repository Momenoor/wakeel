<?php

namespace App\Filament\Shared\ActivityLog;

use AlizHarb\ActivityLog\Widgets\LatestActivityWidget as BaseLatestActivityWidget;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;

/**
 * The activity-log plugin's widget, behind its own permission
 * (config/filament-activity-log.php lists this class in its place).
 */
class LatestActivityWidget extends BaseLatestActivityWidget
{
    use HasWidgetShield;
}
