<?php

namespace App\Models;

use App\Support\Currency;
use App\Support\Dates;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What a matter's marketer earned by a change — a payment from the client, a credit note, a marketer added or taken
 * off, the share changed (MarketingCommissions): below zero when it took back. A staff marketer's is paid in the
 * incentive calculation of its period, a line of its own there; an external marketer's is owed until marked paid.
 */
class MarketingCommission extends Model
{
    public const STAFF = 'staff';

    public const EXTERNAL = 'external';

    protected $fillable = [
        'matter_id',
        'party_id',
        'kind',
        'date',
        'base',
        'amount',
        'description',
        'incentive_calculation_id',
        'paid_at',
        'paid_by',
        'created_by',
    ];

    protected $casts = [
        'date' => 'date',
        'paid_at' => 'date',
        'base' => 'decimal:2',
        'amount' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::creating(function (MarketingCommission $commission): void {
            $commission->created_by ??= auth()->id();
        });
    }

    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class)->withTrashed();
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function calculation(): BelongsTo
    {
        return $this->belongsTo(IncentiveCalculation::class, 'incentive_calculation_id');
    }

    public function paidBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    /** @param  Builder<MarketingCommission>  $query */
    public function scopeExternal(Builder $query): void
    {
        $query->where('kind', self::EXTERNAL);
    }

    /** @param  Builder<MarketingCommission>  $query */
    public function scopeStaff(Builder $query): void
    {
        $query->where('kind', self::STAFF);
    }

    public static function kindLabel(?string $kind): string
    {
        return $kind === self::STAFF ? __('Office staff') : __('Third party');
    }

    /**
     * What it is, as its incentive line says it: on its matter, what it comes to in all (its description) — and,
     * given what of it is in a calculation, that: the rest was paid in one before, or is to be in one after.
     */
    public function label(?float $inCalculation = null): string
    {
        $label = __('Marketing commission on matter :matter (:date): :description', [
            'matter' => $this->matter ? $this->matter->number.'/'.$this->matter->year : '—',
            'date' => $this->date?->format(Dates::dateFormat()) ?? '—',
            'description' => $this->description ?: Currency::text((float) $this->amount),
        ]);

        return $inCalculation === null ? $label : $label.' '.__(':amount of it in this calculation.', ['amount' => Currency::text($inCalculation)]);
    }
}
