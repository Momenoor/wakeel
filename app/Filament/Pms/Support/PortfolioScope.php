<?php

namespace App\Filament\Pms\Support;

use App\Filament\Pms\Resources\Leases\LeaseResource;
use App\Models\OwnerGroup;
use App\Models\Property;
use Illuminate\Database\Eloquent\Builder;

/**
 * The PMS dashboard's and lease table's two portfolio filters — owner group
 * and building (a Property) — applied to each kind of record they narrow.
 * A lease reaches both through its units: lease → units → property →
 * owner group.
 */
final class PortfolioScope
{
    public const OWNER_GROUP = 'owner_group_id';

    public const PROPERTY = 'property_id';

    /**
     * @param  array<string, mixed>|null  $filters  the dashboard's page filters
     * @return array{0: int|null, 1: int|null} [owner group id, property id]
     */
    public static function fromFilters(?array $filters): array
    {
        return [
            filled($filters[self::OWNER_GROUP] ?? null) ? (int) $filters[self::OWNER_GROUP] : null,
            filled($filters[self::PROPERTY] ?? null) ? (int) $filters[self::PROPERTY] : null,
        ];
    }

    /**
     * @param  Builder<\App\Models\Lease>  $query
     * @return Builder<\App\Models\Lease>
     */
    public static function leases(Builder $query, ?int $ownerGroupId, ?int $propertyId): Builder
    {
        return $query
            ->when($propertyId, fn (Builder $query) => $query->whereHas(
                'units',
                fn (Builder $units) => $units->where('property_id', $propertyId),
            ))
            ->when($ownerGroupId, fn (Builder $query) => $query->whereHas(
                'units.property',
                fn (Builder $properties) => $properties->where('owner_group_id', $ownerGroupId),
            ));
    }

    /**
     * @param  Builder<\App\Models\Unit>  $query
     * @return Builder<\App\Models\Unit>
     */
    public static function units(Builder $query, ?int $ownerGroupId, ?int $propertyId): Builder
    {
        return $query
            ->when($propertyId, fn (Builder $query) => $query->where('property_id', $propertyId))
            ->when($ownerGroupId, fn (Builder $query) => $query->whereHas(
                'property',
                fn (Builder $properties) => $properties->where('owner_group_id', $ownerGroupId),
            ));
    }

    /**
     * @param  Builder<\App\Models\Installment>  $query
     * @return Builder<\App\Models\Installment>
     */
    public static function installments(Builder $query, ?int $ownerGroupId, ?int $propertyId): Builder
    {
        if ($ownerGroupId === null && $propertyId === null) {
            return $query;
        }

        return $query->whereHas('lease', fn (Builder $leases) => self::leases($leases, $ownerGroupId, $propertyId));
    }

    /**
     * @return array<int, string>
     */
    public static function ownerGroupOptions(): array
    {
        return OwnerGroup::query()->orderBy('name')->pluck('name', 'id')->all();
    }

    /**
     * Buildings, narrowed to one owner group when one is chosen.
     *
     * @return array<int, string>
     */
    public static function propertyOptions(?int $ownerGroupId = null): array
    {
        return Property::query()
            ->when($ownerGroupId, fn (Builder $query) => $query->where('owner_group_id', $ownerGroupId))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * The lease table with the same two filters already applied.
     */
    public static function leaseTableUrl(?int $ownerGroupId, ?int $propertyId): string
    {
        return LeaseResource::getUrl('index', array_filter([
            'filters' => array_filter([
                'owner_group' => $ownerGroupId ? ['value' => $ownerGroupId] : null,
                'property' => $propertyId ? ['value' => $propertyId] : null,
            ]),
        ]));
    }
}
