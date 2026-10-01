<?php

namespace App\Models;

use App\Observers\CalendarEventObserver;
use App\Services\MMS\Calendar\MatterReferenceMatcher;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[ObservedBy(CalendarEventObserver::class)]
class CalendarEvent extends Model
{
    use SoftDeletes;

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

    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    public function matters(): BelongsToMany
    {
        return $this->belongsToMany(Matter::class, 'calendar_event_matter')->using(CalendarEventMatter::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeNotImportedFromOutlook(Builder $query): Builder
    {
        return $query->where('imported_from_outlook', false);
    }
}
