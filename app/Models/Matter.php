<?php

namespace App\Models;

use App\Enums\AttachmentCategory;
use App\Enums\FeeStatus;
use App\Enums\MatterCollectionStatus;
use App\Enums\MatterCommissiong;
use App\Enums\MatterDifficulty;
use App\Enums\MatterLevel;
use App\Enums\MatterStatus;
use App\Models\Concerns\AnnouncesChanges;
use App\Models\Concerns\HasAttachments;
use App\Models\Contracts\AttachmentOwner;
use App\Observers\MatterObserver;
use App\Services\MMS\Calendar\MatterReferenceMatcher;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * @mixin Builder
 * @mixin SoftDeletes
 */
/**
 * @property mixed $status
 */
#[ObservedBy(MatterObserver::class)]
class Matter extends Model implements AttachmentOwner
{
    use AnnouncesChanges;
    use HasAttachments;
    use HasFactory;
    use LogsActivity;
    use SoftDeletes;

    protected array $_cache = [];

    protected $fillable = [
        'year',
        'number',
        'commissioning',
        'distributed_at',
        'next_session_date',
        'initial_report_at',
        'final_report_at',
        'court_id',
        'type_id',
        'level',
        'difficulty',
        'collection_status',
        'review_count',
        'has_substantive_changes',
        'has_court_penalty',
        'final_report_memo_date',
        'is_office_work',
        'received_at',
        'custom_fields',
        'marketing_rate',
        'marketing_fixed',
    ];

    protected array $dates = [
        'distributed_at',
        'next_session_date',
        'initial_report_at',
        'final_report_at',
        'received_at',
        'created_at',
        'updated_at',
        'deleted_at',
    ];

    protected $casts = [
        'difficulty' => MatterDifficulty::class,
        'level' => MatterLevel::class,
        'collection_status' => MatterCollectionStatus::class,
        'commissioning' => MatterCommissiong::class,
        'distributed_at' => 'datetime',
        'initial_report_at' => 'datetime',
        'final_report_at' => 'datetime',
        'received_at' => 'datetime',
        'next_session_date' => 'datetime',
        'review_count' => 'integer',
        'has_substantive_changes' => 'boolean',
        'has_court_penalty' => 'boolean',
        'marketing_rate' => 'decimal:2',
        'marketing_fixed' => 'decimal:2',
        'final_report_memo_date' => 'date',
        'is_office_work' => 'boolean',
        'created_at' => 'date:Y-m-d',
        'custom_fields' => 'json',
    ];

    public $timestamps = true;

    /**
     * Matters whose status is "In progress": no initial report yet — the same
     * rule as the In progress tab of the matters list and the status accessor.
     *
     * @param  Builder<Matter>  $query
     */
    public function scopeInProgress(Builder $query): void
    {
        $query->whereNull(['initial_report_at', 'final_report_at', 'final_report_memo_date']);
    }

    /**
     * The matters that are someone's own: those they are on (as an assistant, an expert …) and those of the
     * assistants they administer (User::ownMatterPartyIds()). None for one on no matter — never all.
     *
     * @param  Builder<Matter>  $query
     */
    public function scopeOwnedBy(Builder $query, User $user): void
    {
        $parties = $user->ownMatterPartyIds();

        $parties === []
            ? $query->whereRaw('1 = 0')
            : $query->whereHas('matterParties', fn (Builder $q) => $q->whereIn('party_id', $parties));
    }

    /**
     * The matters someone may see: all of them, or — given only ViewOwn:Matter — their own. Fails closed: a
     * user kept to their own matters without a party linked sees none (scopeOwnedBy()). The matters list and
     * every matter picker (MatterSearch) read through it.
     */
    public function scopeVisibleTo(Builder $query, ?User $user): void
    {
        if ($user && ! $user->can('ViewAny:Matter') && $user->can('ViewOwn:Matter')) {
            $query->ownedBy($user);
        }
    }

    /** Whether it is one of someone's own matters (scopeOwnedBy()). */
    public function isOwnedBy(User $user): bool
    {
        $parties = $user->ownMatterPartyIds();

        return $parties !== [] && $this->matterParties()->whereIn('party_id', $parties)->exists();
    }

    protected static function booted()
    {
        // A year before the oldest: the matter numbers read in emails and events go back to it.
        static::saved(function (Matter $matter) {
            if (($matter->wasRecentlyCreated || $matter->wasChanged('year')) && (int) $matter->year < MatterReferenceMatcher::firstYear()) {
                MatterReferenceMatcher::forgetFirstYear();
            }
        });

        static::saved(function (Matter $matter) {
            if ($matter->isDirty('custom_fields') || $matter->wasRecentlyCreated) {
                $customFields = $matter->custom_fields ?? [];

                // Get current meta field names to identify what to remove
                $existingFieldNames = $matter->metas()->pluck('field_name')->toArray();
                $newFieldNames = array_keys($customFields);

                // Remove metas that are no longer in custom_fields
                $fieldsToRemove = array_diff($existingFieldNames, $newFieldNames);
                if (! empty($fieldsToRemove)) {
                    $matter->metas()->whereIn('field_name', $fieldsToRemove)->delete();
                }

                // Update or create metas from custom_fields
                foreach ($customFields as $key => $value) {
                    $matter->metas()->updateOrCreate(
                        ['field_name' => $key],
                        ['field_value' => is_array($value) ? json_encode($value) : $value]
                    );
                }
            }
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll();
    }

    public function children(): HasMany
    {
        return $this->hasMany(Matter::class, 'parent_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Matter::class, 'parent_id');
    }

    public function court(): BelongsTo
    {
        return $this->belongsTo(Court::class);
    }

    /**
     * All matter_party rows (including representatives).
     */
    public function matterParties(): HasMany
    {
        return $this->hasMany(MatterParty::class, 'matter_id');
    }

    /**
     * Top-level matter_party rows only (excludes representatives).
     * Used by the outer Filament Repeater.
     */
    public function mainParties(): HasMany
    {
        return $this->hasMany(MatterParty::class, 'matter_id')
            ->where(fn ($q) => $q->whereNull('parent_id')->orWhere('parent_id', 0));
    }

    /**
     * Top-level matter_party rows with role 'party' only.
     */
    public function mainPartiesOnly(): HasMany
    {
        return $this->hasMany(MatterParty::class, 'matter_id')
            ->where(fn ($q) => $q->whereNull('parent_id')->orWhere('parent_id', 0))
            ->where('role', 'party');
    }

    /**
     * Top-level matter_party rows with role 'expert' only.
     */
    public function expertsOnly(): HasMany
    {
        return $this->hasMany(MatterParty::class, 'matter_id')
            ->where(fn ($q) => $q->whereNull('parent_id')->orWhere('parent_id', 0))
            ->where('role', 'expert');
    }

    /**
     * @return HasMany<MatterParty, $this>
     */
    /**
     * Its emails, each way: sent from it, and the replies collected.
     *
     * @return HasMany<MatterEmail, $this>
     */
    public function emails(): HasMany
    {
        return $this->hasMany(MatterEmail::class);
    }

    /**
     * Its bulk mail campaigns.
     *
     * @return HasMany<BulkMailCampaign, $this>
     */
    public function bulkMailCampaigns(): HasMany
    {
        return $this->hasMany(BulkMailCampaign::class);
    }

    /**
     * Addresses its parties brought in, by putting them in To or CC.
     *
     * @return HasMany<MatterContact, $this>
     */
    public function contacts(): HasMany
    {
        return $this->hasMany(MatterContact::class);
    }

    /**
     * Its contacts who are no party, as corrected on its Contacts tab.
     *
     * @return HasMany<MatterContactDetail, $this>
     */
    public function contactDetails(): HasMany
    {
        return $this->hasMany(MatterContactDetail::class);
    }

    /**
     * The steps of its progress — letters, meetings, emails, reports …
     *
     * @return HasMany<MatterProgress, $this>
     */
    public function progress(): HasMany
    {
        return $this->hasMany(MatterProgress::class);
    }

    /**
     * Those added at a meeting's minutes — not parties of the matter, but
     * kept with it (MinutesService::registerAttendees).
     *
     * @return HasMany<MatterParty, $this>
     */
    public function attendeesOnly(): HasMany
    {
        return $this->hasMany(MatterParty::class, 'matter_id')->where('role', 'attendee');
    }

    /** Who brought it in (MarketingCommissions): its parties of the role "marketer", staff or external. */
    public function marketers(): HasMany
    {
        return $this->hasMany(MatterParty::class, 'matter_id')->where('role', 'marketer');
    }

    /** What its marketers earned (MarketingCommission). */
    public function marketingCommissions(): HasMany
    {
        return $this->hasMany(MarketingCommission::class);
    }

    /**
     * @return HasMany<MatterParty, $this>
     */
    public function assistantsOnly(): HasMany
    {
        return $this->hasMany(MatterParty::class, 'matter_id')
            ->where(['role' => 'expert', 'type' => 'assistant']);
    }

    /**
     * Its assistants with their names, read once for this matter — the page asked for them in four places, each
     * render (whether it has any, their names, the OneDrive folders').
     *
     * @return Collection<int, MatterParty>
     */
    public function assistantList(): Collection
    {
        return $this->loadMissing('assistantsOnly.party:id,name')->assistantsOnly;
    }

    public function isOfficeWork(): bool
    {
        return $this->is_office_work;
    }

    // -----------------------------------------------------------------------
    // FOR QUERYING / READING
    // BelongsToMany gives you Party models directly, useful outside forms.
    // e.g. $matter->parties, $matter->mainPartiesQuery
    // -----------------------------------------------------------------------

    /**
     * All parties in this matter via belongsToMany.
     * e.g. $matter->parties->pluck('name')
     */
    public function parties(): BelongsToMany
    {
        return $this->belongsToMany(Party::class, 'matter_party')
            ->withPivot('id', 'type', 'role', 'parent_id');
    }

    public function mainExpertsOnly(): BelongsToMany
    {
        return $this->belongsToMany(Party::class, 'matter_party')
            // Filter the related Party record's JSON column
            ->whereJsonContains('parties.role', [
                'role' => 'expert',
                'type' => 'certified',
            ])
            // Filter the relationship link (Pivot Table)
            ->withPivot('id', 'type', 'role as pivot_role', 'parent_id');
    }

    /**
     * Top-level parties only via belongsToMany (no representatives).
     */
    public function mainPartiesQuery(): BelongsToMany
    {
        return $this->belongsToMany(Party::class, 'matter_party')
            ->whereJsonContains('parties.role', ['role' => 'party'])
            ->withPivot('id', 'type', 'role', 'parent_id')
            ->wherePivotIn('parent_id', [null, '', 0]);
    }

    /**
     * Parties (role = 'party') grouped by type with per-type indexing.
     * e.g. Plaintiff #1, Plaintiff #2, Defendant #1, Defendant #2 ...
     *
     * Used by RepeatableEntry::make('indexedParties') in the infolist.
     */
    // Add this at the top of the class — a plain PHP property, invisible to Eloquent

    public function getIndexedPartiesAttribute()
    {
        if (isset($this->_cache['indexedParties'])) {
            return $this->_cache['indexedParties'];
        }

        $relation = $this->relationLoaded('mainPartiesOnly')
            ? $this->mainPartiesOnly
            : $this->mainPartiesOnly()->with('party', 'representatives.party')->get();

        $result = $relation
            ->groupBy('type')
            ->flatMap(function ($group) {
                return $group->values()->map(function ($mp, $i) {
                    $mp->role_index = $i + 1;

                    return $mp;
                });
            });

        $this->_cache['indexedParties'] = $result;

        return $result;
    }

    public function getIndexedExpertsAttribute()
    {
        if (isset($this->_cache['indexedExperts'])) {
            return $this->_cache['indexedExperts'];
        }

        $relation = $this->relationLoaded('expertsOnly')
            ? $this->expertsOnly
            : $this->expertsOnly()->with('party')->get();

        $result = $relation
            ->groupBy('type')
            ->flatMap(function ($group) {
                return $group->values()->map(function ($mp, $i) {
                    $mp->role_index = $i + 1;

                    return $mp;
                });
            });

        $this->_cache['indexedExperts'] = $result;

        return $result;
    }

    public function fees(): HasMany
    {
        return $this->hasMany(Fee::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(Allocation::class);
    }

    /** What was paid out for its procedures (MatterExpense). */
    public function expenses(): HasMany
    {
        return $this->hasMany(MatterExpense::class);
    }

    /**
     * Its expenses: charged to the client (its fees of type "Matter expenses", VAT never on them), received of
     * them, paid out, and the balance — received less paid out: above zero still to spend or to refund, below
     * zero paid out before it was received, to collect. From its fees, their payments and its expenses as loaded.
     *
     * @return array{charged: float, received: float, spent: float, balance: float}
     */
    public function expensesSummary(): array
    {
        $fees = $this->fees->filter(fn (Fee $fee): bool => (bool) $fee->type?->isPassThrough());
        $received = round((float) $fees->sum(fn (Fee $fee): float => (float) $fee->allocations->sum('amount')), 2);
        $spent = round((float) $this->expenses->sum('amount'), 2);

        return [
            'charged' => round((float) $fees->sum(fn (Fee $fee): float => $fee->total), 2),
            'received' => $received,
            'spent' => $spent,
            'balance' => round($received - $spent, 2),
        ];
    }

    /**
     * Update the matter's collection status based on its fees.
     */
    public function updateCollectionStatus(?Collection $fees = null): void
    {
        // Read afresh, unless given — its fees read already, with many matters' at once (VatRowMerger).
        $this->collection_status = $this->collectionStatusFor($fees ?? $this->fees()->get());

        if ($this->isDirty('collection_status')) {
            $this->saveQuietly();
        }
    }

    /**
     * What its collection status is, from these fees (its own) and its payments — set nowhere (a preview reads it
     * too: FeeVatAndStatusFix).
     */
    public function collectionStatusFor(Collection $fees): MatterCollectionStatus
    {
        if ($fees->isEmpty()) {
            return MatterCollectionStatus::NO_FEES;
        }

        // What is collected: each fee's total, VAT included.
        $totalAmount = (float) $fees->sum(fn ($fee) => abs($fee->total));
        $totalAllocated = (float) $this->allocations->sum(fn ($allocation) => abs($allocation->amount));

        // As a fee's: in whole fils, within a dirham paid — and paid over, paid.
        return match (Fee::statusOf($totalAmount, $totalAllocated)) {
            FeeStatus::UNPAID => MatterCollectionStatus::UNPAID,
            FeeStatus::PARTIAL => MatterCollectionStatus::PARTIAL,
            default => MatterCollectionStatus::PAID,
        };
    }

    /**
     * @return BelongsTo<Type, $this>
     */
    public function type(): BelongsTo
    {
        return $this->belongsTo(Type::class);
    }

    public function metas(): HasMany
    {
        return $this->hasMany(MatterMeta::class);
    }

    public function status(): Attribute
    {
        return new Attribute(
            get: function () {
                if (! $this->initial_report_at) {
                    return MatterStatus::IN_PROGRESS;
                }
                if (! $this->final_report_at) {
                    return MatterStatus::INITIALED;
                }

                return MatterStatus::FINALIZED;
            }
        );
    }

    public function notes(): HasMany
    {
        return $this->hasMany(Note::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }

    public function finalReportSubmission(): void
    {
        $this->final_report_at = now();
        if ($this->initial_report_at === null) {
            $this->initial_report_at = now();
        }
        if ($this->final_report_memo_date === null) {
            $this->final_report_memo_date = now();
        }
        $this->save();
    }

    /**
     * This matter's folders in its assistants' OneDrive.
     *
     * @return HasMany<MatterOneDriveFolder, $this>
     */
    public function oneDriveFolders(): HasMany
    {
        return $this->hasMany(MatterOneDriveFolder::class);
    }

    public function incentiveLines(): HasMany
    {
        return $this->hasMany(IncentiveLine::class);
    }

    public function getReference(): string
    {
        return "{$this->number}/{$this->year}";
    }

    public function getReferenceAttribute(): string
    {
        return $this->getReference();
    }

    /**
     * Letters issued on this matter, newest first.
     */
    public function letters(): HasMany
    {
        return $this->hasMany(MatterLetter::class)->latest('id');
    }

    /**
     * Meetings' minutes on this matter, newest first.
     */
    public function minutes(): HasMany
    {
        return $this->hasMany(MatterMinutes::class)->latest('number');
    }

    public function requests(): HasMany
    {
        return $this->hasMany(MatterRequest::class);
    }

    public function hasFinalReport(): bool
    {
        return $this->final_report_at !== null;
    }

    public function calendarEvents(): HasMany
    {
        return $this->hasMany(CalendarEvent::class);
    }

    public function bulkCalendarEvents(): BelongsToMany
    {
        return $this->belongsToMany(CalendarEvent::class, 'calendar_event_matter')->using(CalendarEventMatter::class);
    }

    /**
     * Every calendar event for this matter, however it was linked — as its
     * main matter or among several.
     *
     * @return Builder<CalendarEvent>
     */
    public function linkedCalendarEvents(): Builder
    {
        return CalendarEvent::query()->where(fn (Builder $query) => $query
            ->where('matter_id', $this->getKey())
            ->orWhereHas('matters', fn (Builder $q) => $q->whereKey($this->getKey())));
    }

    public function attachmentMatterId(): ?int
    {
        return $this->getKey();
    }

    /** @return list<AttachmentCategory> */
    public function attachmentCategories(): array
    {
        return [AttachmentCategory::GENERAL, AttachmentCategory::COURT_DOCUMENT, AttachmentCategory::REPORT, AttachmentCategory::CORRESPONDENCE, AttachmentCategory::MINUTES_SIGNED, AttachmentCategory::CONTRACT];
    }
}
