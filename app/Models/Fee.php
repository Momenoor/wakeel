<?php

namespace App\Models;

use App\Enums\AttachmentCategory;
use App\Enums\FeeStatus;
use App\Enums\FeeType;
use App\Models\Concerns\AnnouncesChanges;
use App\Models\Concerns\HasAttachments;
use App\Models\Contracts\AttachmentOwner;
use App\Services\MMS\FeeChangeNotifier;
use App\Services\MMS\IncentiveReversals;
use App\Services\MMS\MarketingCommissions;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

// Who is to hear of it is told of a fee or a collection added, changed or deleted.
#[ObservedBy(FeeChangeNotifier::class)]
class Fee extends Model implements AttachmentOwner
{
    use AnnouncesChanges;
    use HasAttachments;
    use HasFactory;
    use LogsActivity;

    /** Short of its total, or over it, by less than this (a dirham), a fee or a matter is paid. */
    public const PAID_WITHIN = 1.0;

    /** The credit note it is being reduced by, while it is (FeeCreditNotes) — so a paid incentive says why. */
    public ?FeeCreditNote $creditedBy = null;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll();
    }

    protected $fillable = [
        'matter_id',
        'user_id',
        'type',
        'amount',
        'vat',
        'is_vat_included',
        'is_vat_exempt',
        'date',
        'description',
        'status',
    ];

    protected $casts = [
        'date' => 'date',
        'status' => FeeStatus::class,
        // Its basic amount (VAT-exclusive); its VAT beside it, its total the two (App\Support\Vat).
        'amount' => 'decimal:2',
        'vat' => 'decimal:2',
        'is_vat_included' => 'boolean',
        // VAT does not apply to it: no VAT, whatever its type (FeeVatFields).
        'is_vat_exempt' => 'boolean',
        'type' => FeeType::class,
    ];

    protected static function boot()
    {
        parent::boot();

        static::deleting(function (Fee $fee) {
            $fee->allocations()->delete();

            // Its incentive already paid: the paid calculation stays as it was, and each assistant's share of
            // it is taken back in the next calculation (IncentiveReversals). In a draft it simply goes.
            app(IncentiveReversals::class)->record($fee);
        });

        static::updated(function (Fee $fee) {
            // Its basic amount corrected, or reduced by a credit note, after its incentive was paid: the change's
            // part of it settled in the next calculation (IncentiveReversals). Nothing when it was not paid.
            if ($fee->wasChanged('amount')) {
                app(IncentiveReversals::class)->recordChange($fee, (float) $fee->getOriginal('amount'), $fee->creditedBy);
            }
        });

        static::created(function (Fee $fee) {
            // A fee added: a fixed marketing share is spread over more (MarketingCommissions).
            app(MarketingCommissions::class)->afterChange($fee->matter);
        });

        static::updated(function (Fee $fee) {
            // Corrected, credited or of another type: what the matter's marketers earned, again.
            if ($fee->wasChanged(['amount', 'vat', 'type'])) {
                app(MarketingCommissions::class)->afterChange($fee->matter);
            }
        });

        static::saving(function (Fee $fee) {
            // Who entered it: whoever added it — kept when it is changed or a payment is recorded on it later.
            if (! $fee->exists && $fee->user_id === null) {
                $fee->user_id = auth()->id();
            }
            if (! $fee->date) {
                $fee->date = now();
            }
            // Normalise the sign only while the amount is actually being set.
            // Firing on every save meant an unrelated write — recalculating a
            // status, for instance — silently negated a legacy positive
            // deduction fee and left its allocations pointing the other way,
            // so the pair no longer cancelled. Existing rows are corrected
            // deliberately, together with their allocations, from the Fee Data
            // Maintenance page.
            if ($fee->type?->isNegative() && $fee->amount > 0 && ($fee->isDirty('amount') || ! $fee->exists)) {
                $fee->amount = -abs($fee->amount);
            }
            if ($fee->type === FeeType::COURT_PENALITY) {
                $fee->matter->update(['has_court_penalty' => true]);
            }
        });

        static::saved(function (Fee $fee) {
            $fee->matter?->updateCollectionStatus();
        });

        static::deleted(function (Fee $fee) {
            if ($fee->type === FeeType::COURT_PENALITY) {
                // Only clear if no other court penalty fees remain
                $hasOtherPenalties = $fee->matter->fees()
                    ->where('type', FeeType::COURT_PENALITY)
                    ->where('id', '!=', $fee->id)
                    ->exists();

                if (! $hasOtherPenalties) {
                    $fee->matter->update(['has_court_penalty' => false]);
                }
            }
            $fee->matter?->updateCollectionStatus();
            app(MarketingCommissions::class)->afterChange($fee->matter);
        });
    }

    /**
     * @return BelongsTo<Matter, $this>
     */
    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    /**
     * @return HasMany<Allocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(Allocation::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function incentiveLines(): HasMany
    {
        return $this->hasMany(IncentiveLine::class);
    }

    /** The credit notes it was reduced by (FeeCreditNote). */
    public function creditNotes(): HasMany
    {
        return $this->hasMany(FeeCreditNote::class);
    }

    public function getTotalAllocatedAttribute(): float
    {
        return (float) $this->allocations()->sum('amount');
    }

    /**
     * What it comes to, VAT included: its basic amount and its VAT — what is billed and collected. (A query of
     * fees naming a sum "total" is read through this by pluck(): name it otherwise.)
     */
    public function getTotalAttribute(): float
    {
        return round((float) $this->amount + (float) $this->vat, 2);
    }

    /** Its amount as entered: the total when entered VAT included, else the basic (App\Filament\Support\FeeVatFields). */
    public function getEnteredAmountAttribute(): float
    {
        return self::entered((float) $this->amount, (float) $this->vat, (bool) $this->is_vat_included);
    }

    /** An amount as entered, from its basic and VAT: their total when entered VAT included, else the basic. */
    public static function entered(float $basic, float $vat, bool $included): float
    {
        return $included ? round($basic + $vat, 2) : $basic;
    }

    /** The VAT it charges: a VAT row of before is all VAT; a fee its own. */
    public function vatCharged(): float
    {
        return $this->type === FeeType::VAT ? (float) $this->amount : (float) $this->vat;
    }

    /** Its basic amount: a VAT row of before has none (it is all VAT). */
    public function basicCharged(): float
    {
        return $this->type === FeeType::VAT ? 0.0 : (float) $this->amount;
    }

    /** SQL: a fee row's total, VAT included — "SUM(".Fee::totalSql('f').")". */
    public static function totalSql(string $table = 'fees'): string
    {
        return "({$table}.amount + {$table}.vat)";
    }

    /** SQL: the VAT a fee row charges, as vatCharged(). */
    public static function vatChargedSql(string $table = 'fees'): string
    {
        return "CASE WHEN {$table}.type = '".FeeType::VAT->value."' THEN {$table}.amount ELSE {$table}.vat END";
    }

    /**
     * SQL: the share of a payment on a fee row that is its basic amount, or its VAT — a payment pays the basic
     * and the VAT alike; a fee of no VAT, all basic.
     */
    public static function shareSql(string $table, bool $vat): string
    {
        return $vat
            ? "COALESCE({$table}.vat / NULLIF(".self::totalSql($table).', 0), 0)'
            : "COALESCE({$table}.amount / NULLIF(".self::totalSql($table).', 0), 1)';
    }

    public function getBalanceAttribute(): float
    {
        return (float) ($this->total - $this->total_allocated);
    }

    /**
     * How much a revenue fee may legitimately hold above its own amount.
     *
     * On a commission matter the client's gross payment is allocated to the
     * revenue fee while the office-share line carries the offsetting negative,
     * so the revenue line holds (fee + commission) and nets out correctly at
     * matter level. Without this allowance every one of those fees reads as
     * overpaid — ~405 of them in production — the moment updateStatus() runs.
     *
     * Deduction fees get no allowance: they are the offset, not the offsettee.
     */
    /** @var array<int, float>|null every matter's offset allowance, during withOffsetAllowances() */
    private static ?array $offsetAllowances = null;

    /**
     * Runs $callback with every matter's offset allowance read in one query
     * — for going through all fees: one query per fee otherwise (3,000 to
     * open the fee data maintenance page).
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withOffsetAllowances(callable $callback): mixed
    {
        static::$offsetAllowances = static::query()
            ->whereIn('type', FeeType::deductionTypeValues())
            ->whereNotNull('matter_id')
            ->groupBy('matter_id')
            // Not "total": pluck() would read the fee's own total (getTotalAttribute) instead.
            ->selectRaw('matter_id, SUM(ABS(amount)) as deductions')
            ->pluck('deductions', 'matter_id')
            ->map(fn ($total) => (float) $total)
            ->all();

        try {
            return $callback();
        } finally {
            static::$offsetAllowances = null;
        }
    }

    private function offsetAllowance(): float
    {
        if ($this->type?->isNegative() ?? false) {
            return 0.0;
        }

        if (! $this->matter_id) {
            return 0.0;
        }

        // Within withOffsetAllowances(): every matter's, read once.
        if (static::$offsetAllowances !== null) {
            return static::$offsetAllowances[$this->matter_id] ?? 0.0;
        }

        return (float) static::query()
            ->where('matter_id', $this->matter_id)
            ->whereIn('type', FeeType::deductionTypeValues())
            ->sum(DB::raw('ABS(amount)'));
    }

    /**
     * Update the fee status based on allocations.
     *
     * Compares magnitudes so the ladder reads identically for deduction fees,
     * which are stored negative and paid down negatively — previously a fully
     * settled -750 office share reported UNPAID because its allocation was
     * also negative.
     */
    public function updateStatus(): void
    {
        $this->syncStatus();

        if ($this->isDirty('status')) {
            $this->save();
        }

        // Also update matter collection status
        $this->matter?->updateCollectionStatus();
    }

    /**
     * Compute and set the status in memory without persisting anything.
     *
     * Split out from updateStatus() so a diagnostic can ask "what would this
     * become?" without writing to live financial records.
     */
    public function syncStatus(): void
    {
        $allocated = abs((float) ($this->relationLoaded('allocations')
            ? $this->allocations->sum('amount')
            : $this->allocations()->sum('amount')));

        // What is collected is the total, VAT included.
        $this->status = self::statusOf($this->total, $allocated, $this->offsetAllowance());
    }

    /**
     * Paid, partly, over or not at all — what is collected against the total, both in whole fils (a float sum of
     * payments is never exactly its total), within a dirham either way paid: what a VAT split and rounding leave.
     * A matter's collection status reads the same (Matter::collectionStatusFor()).
     */
    public static function statusOf(float $total, float $collected, float $allowance = 0.0): FeeStatus
    {
        $total = round(abs($total), 2);
        $collected = round(abs($collected), 2);

        return match (true) {
            $collected <= 0 => FeeStatus::UNPAID,
            round($total - $collected, 2) >= self::PAID_WITHIN => FeeStatus::PARTIAL,
            round($collected - $total - $allowance, 2) < self::PAID_WITHIN => FeeStatus::PAID,
            default => FeeStatus::OVERPAID,
        };
    }

    public function attachmentMatterId(): ?int
    {
        return $this->matter_id;
    }

    /** @return list<AttachmentCategory> */
    public function attachmentCategories(): array
    {
        // The first is the one offered by default.
        return [AttachmentCategory::COURT_DECISION, AttachmentCategory::FEE_INVOICE, AttachmentCategory::TAX_INVOICE, AttachmentCategory::CONTRACT, AttachmentCategory::GENERAL];
    }
}
