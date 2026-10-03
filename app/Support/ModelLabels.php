<?php

namespace App\Support;

use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;

/**
 * A model's name in the reader's language, singular and plural — for the
 * tables of reports and widgets, which have no resource to name them.
 * Filament otherwise took the class name ("matter", "matters") or added
 * an "s" to an Arabic word ("القضيةs") in their filters and column modals.
 *
 * A model with a resource is named as its resource names it (in whichever
 * panel); the others are named here.
 */
final class ModelLabels
{
    /**
     * Models with no resource of their own: [singular, plural] translation keys.
     *
     * @var array<string, array{string, string}>
     */
    private const OTHERS = [
        'MatterParty' => ['Matter', 'Matters'],
        'Installment' => ['Instalment', 'Instalments'],
        'InstallmentPayment' => ['Payment', 'Payments'],
        'FlightTicket' => ['Flight ticket', 'Flight Tickets'],
        'IncentiveAssistantLine' => ['Incentive line', 'Incentive lines'],
        'Unit' => ['Unit', 'Units'],
        'Fee' => ['Fee', 'Fees'],
        'Activity' => ['Activity', 'Activities'],
    ];

    public static function singular(Model|string|null $model): ?string
    {
        return self::labels($model)[0] ?? null;
    }

    public static function plural(Model|string|null $model): ?string
    {
        return self::labels($model)[1] ?? null;
    }

    /**
     * @return array{string, string}|null
     */
    private static function labels(Model|string|null $model): ?array
    {
        if ($model === null) {
            return null;
        }

        $class = is_string($model) ? $model : $model::class;

        foreach (Filament::getPanels() as $panel) {
            $resource = $panel->getModelResource($class);

            if ($resource !== null) {
                return [$resource::getModelLabel(), $resource::getPluralModelLabel()];
            }
        }

        $keys = self::OTHERS[class_basename($class)] ?? null;

        return $keys === null ? null : [__($keys[0]), __($keys[1])];
    }
}
