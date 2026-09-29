<?php

namespace App\Models;

use App\Enums\PMS\Emirate;
use App\Enums\PMS\PropertyType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Property extends Model
{
    use HasFactory;
    use LogsActivity;
    use SoftDeletes;

    protected $fillable = [
        'name',
        'emirate',
        'address',
        'municipality',
        'suburb',
        'area',
        'title_deed_number',
        'title_deed_date',
        'plot_number',
        'property_type',
        'property_number',
        'owner_group_id',
        'owner_group_bank_account_id',
        'total_units',
        'year_built',
    ];

    protected $casts = [
        'emirate' => Emirate::class,
        'property_type' => PropertyType::class,
        'title_deed_date' => 'date',
        'total_units' => 'integer',
        'year_built' => 'integer',
    ];

    protected static function booted(): void
    {
        // A bank account only means something inside its own group — a
        // property that leaves the group (or never had one) keeps no account.
        static::saving(function (Property $property): void {
            if ($property->getAttribute('owner_group_id') === null) {
                $property->setAttribute('owner_group_bank_account_id', null);
            }
        });

        // A property with a unit on a lease or a quotation is part of that
        // record's history — deleting it would leave it pointing at nothing.
        static::deleting(function (Property $property): void {
            if ($reason = $property->deletionBlockedReason()) {
                throw new RuntimeException($reason);
            }
        });

        // Its units go with it — they used to stay behind, listed under a
        // property that no longer exists.
        static::deleted(function (Property $property): void {
            if (! $property->isForceDeleting()) {
                $property->units()->get()->each(fn (Unit $unit) => $unit->delete());
            }
        });

        // …and come back with it: the units deleted along with it, not ones
        // deleted on their own before.
        static::restoring(function (Property $property): void {
            $property->units()->onlyTrashed()
                ->where('deleted_at', '>=', $property->getAttribute('deleted_at'))
                ->get()
                ->each(fn (Unit $unit) => $unit->restore());
        });
    }

    /**
     * @return list<int>
     */
    private function allUnitIds(): array
    {
        return $this->units()->withTrashed()->pluck('id')->all();
    }

    /**
     * Any lease, even a deleted one, ever held one of its units.
     */
    public function hasLeaseHistory(): bool
    {
        return DB::table('lease_unit')->whereIn('unit_id', $this->allUnitIds())->exists();
    }

    public function hasQuotationHistory(): bool
    {
        return DB::table('quotation_unit')->whereIn('unit_id', $this->allUnitIds())->exists();
    }

    /**
     * Why this property can't be deleted, or null when it can.
     */
    public function deletionBlockedReason(): ?string
    {
        return match (true) {
            $this->hasLeaseHistory() => __('This property has units linked to a lease and cannot be deleted.'),
            $this->hasQuotationHistory() => __('This property has units on a quotation and cannot be deleted.'),
            default => null,
        };
    }

    /**
     * @return BelongsTo<OwnerGroup, $this>
     */
    public function ownerGroup(): BelongsTo
    {
        return $this->belongsTo(OwnerGroup::class);
    }

    /**
     * The group account this property's rent is paid into.
     *
     * @return BelongsTo<OwnerGroupBankAccount, $this>
     */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(OwnerGroupBankAccount::class, 'owner_group_bank_account_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll();
    }

    /**
     * @return HasMany<Unit, $this>
     */
    public function units(): HasMany
    {
        return $this->hasMany(Unit::class);
    }

    /**
     * The parties (in the 'owner' role) holding a stake in this property.
     *
     * @return BelongsToMany<Party, $this>
     */
    public function owners(): BelongsToMany
    {
        return $this->belongsToMany(Party::class, 'owner_property', 'property_id', 'owner_party_id')
            ->withPivot('ownership_percentage')
            ->withTimestamps();
    }

    /**
     * The group every owner on this property shares — heirs administering
     * an inherited property as one estate, typically. Derived from the
     * owners themselves (their profiles all pointing at the same group),
     * for a property that predates or was never set up with the direct
     * `owner_group_id` link. Anything less uniform (a mix of groups, or
     * owners with no group at all) has no single group to return.
     */
    public function commonOwnerGroup(): ?OwnerGroup
    {
        $owners = $this->owners()->with('ownerProfile.ownerGroup')->get();

        if ($owners->isEmpty()) {
            return null;
        }

        $groupIds = $owners
            ->map(fn (Party $owner): ?int => $owner->ownerProfile?->getAttribute('owner_group_id'))
            ->unique();

        if ($groupIds->count() === 1 && $groupIds->first() !== null) {
            return $owners->first()?->ownerProfile?->getAttribute('ownerGroup');
        }

        return null;
    }

    /**
     * What a contract's "Landlord" line should show: the shared group's
     * collective name ("Legal Heirs of Mahmoud Kalbat") standing in for
     * listing every owner individually, falling back to each owner's own
     * name when there is no single group all of them belong to.
     */
    public function landlordName(): string
    {
        $owners = $this->owners()->get();

        if ($owners->isEmpty()) {
            return '';
        }

        return $this->commonOwnerGroup()?->name ?? $owners->pluck('name')->implode(', ');
    }
}
