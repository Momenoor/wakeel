<?php

namespace App\Models;

use App\Enums\CalendarEventSource;
use App\Observers\CalendarEventObserver;
use App\Services\MMS\Calendar\MatterReferenceMatcher;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

#[ObservedBy(CalendarEventObserver::class)]
class CalendarEvent extends Model
{
    use LogsActivity;
    use SoftDeletes;

    /** Its appointments: on the matter's history. */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['matter_id', 'title', 'start_datetime', 'location'])->logOnlyDirty()->dontLogEmptyChanges();
    }

    /** What an event changed in Wakeel puts on Outlook (CalendarEventObserver::updated()). */
    public const OUTLOOK_FIELDS = ['title', 'start_datetime', 'end_datetime', 'location', 'description'];

    /** This save brings Outlook's own values (OutlookCalendarSync): nothing to send back. Not a column. */
    public bool $fromOutlook = false;

    protected $fillable = [
        'matter_id',
        'outlook_event_id',
        'title',
        'ignored_references',
        'description',
        'start_datetime',
        'end_datetime',
        'location',
        'type',
        'update_next_session_date',
        'synced_to_outlook',
        'imported_from_outlook',
        'is_teams_meeting',
        'online_meeting_url',
        'attendance',
        'attendance_read_at',
        'created_by',
        'is_all_day',
    ];

    protected $casts = [
        'start_datetime' => 'datetime',
        'end_datetime' => 'datetime',
        'update_next_session_date' => 'boolean',
        'synced_to_outlook' => 'boolean',
        'imported_from_outlook' => 'boolean',
        'is_teams_meeting' => 'boolean',
        'is_all_day' => 'boolean',
        'ignored_references' => 'array',
        // Who joined its Teams call, and when (TeamsAttendance).
        'attendance' => 'array',
        'attendance_read_at' => 'datetime',
    ];

    /**
     * The matter numbers its title names, less the ones marked as not that
     * matter for this event.
     *
     * @return list<array{number: string, year: int}>
     */
    public function matterReferences(): array
    {
        $ignored = $this->ignored_references ?? [];

        return array_values(array_filter(
            MatterReferenceMatcher::references($this->title),
            fn (array $ref): bool => ! in_array(MatterReferenceMatcher::key($ref), $ignored, true),
        ));
    }

    /** Its alarms, for each person and time (App\Services\MMS\Calendar\CalendarAlarms). */
    public function alerts(): HasMany
    {
        return $this->hasMany(CalendarEventAlert::class);
    }

    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    public function matters(): BelongsToMany
    {
        return $this->belongsToMany(Matter::class, 'calendar_event_matter')->using(CalendarEventMatter::class);
    }

    /** The minutes of the meeting, when it has some. */
    public function minutes(): HasMany
    {
        return $this->hasMany(MatterMinutes::class, 'calendar_event_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Made in the system, or outside it (Outlook, Teams, a phone) and brought in by the sync. */
    public function source(): CalendarEventSource
    {
        return $this->imported_from_outlook ? CalendarEventSource::OUTSIDE : CalendarEventSource::SYSTEM;
    }

    public function scopeNotImportedFromOutlook(Builder $query): Builder
    {
        return $query->where('imported_from_outlook', false);
    }
}
