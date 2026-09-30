<?php

namespace App\Models;

use App\Services\MMS\Calendar\UnmatchedEventReferences;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * An event's link to a matter. Linking or unlinking changes which events
 * still name a matter that isn't accounted for, so the cached list of
 * those is forgotten.
 */
class CalendarEventMatter extends Pivot
{
    protected $table = 'calendar_event_matter';

    public $incrementing = true;

    protected static function booted(): void
    {
        static::created(fn () => UnmatchedEventReferences::forget());
        static::deleted(fn () => UnmatchedEventReferences::forget());
    }
}
