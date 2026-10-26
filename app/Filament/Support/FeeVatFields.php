<?php

namespace App\Filament\Support;

use App\Enums\FeeType;
use App\Models\Fee;
use App\Support\Currency;
use App\Support\Vat;
use Closure;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\HtmlString;

/**
 * A fee's VAT on every form a fee is added or changed on — the matter view's Add Fee and Edit Fee, the matter
 * form's fees: "VAT not applicable" (a fee VAT does not apply to, kept with none), "Including VAT" (on for the
 * types entered VAT included, an expert fee — FeeType), the basic, VAT and total as typed, and what is saved —
 * split by App\Support\Vat, nowhere else.
 */
final class FeeVatFields
{
    /** "VAT not applicable": for the types VAT is charged on, a fee of one that VAT does not apply to — none on it. */
    public static function exemptToggle(): Toggle
    {
        return Toggle::make('is_vat_exempt')
            ->label(__('VAT not applicable'))
            ->default(false)
            ->visible(fn (Get $get, $record = null): bool => self::carriesVat($get('type') ?? self::fee($record)?->type))
            ->inline(false)
            ->live();
    }

    /** "Including VAT": shown for the types VAT is charged on, VAT applying; set to the type's own default when it is chosen. */
    public static function toggle(): Toggle
    {
        return Toggle::make('is_vat_included')
            ->label(__('Including VAT'))
            ->default(fn (Get $get): bool => self::includedByDefault($get('type')))
            ->visible(fn (Get $get, $record = null): bool => self::carriesVat($get('type') ?? self::fee($record)?->type) && ! $get('is_vat_exempt'))
            ->inline(false)
            ->live();
    }

    /** For the type select: "Including VAT" follows the type chosen. */
    public static function typeChosen(): Closure
    {
        return fn ($state, Set $set) => $set('is_vat_included', self::includedByDefault($state));
    }

    /**
     * "Basic ⃁ 1,000.00 · VAT ⃁ 50.00 · Total ⃁ 1,050.00", as typed — a fee as it was (its amount and "Including
     * VAT" untouched) as it is kept.
     */
    public static function preview(): Text
    {
        return Text::make(function (Get $get, $record = null): ?HtmlString {
            // The fee changed (Edit Fee, the matter form's row); none when adding one to a matter.
            $record = self::fee($record);
            if (blank($get('amount')) || ! self::carriesVat($get('type') ?? $record?->type)) {
                return null;
            }
            if ($get('is_vat_exempt')) {
                return new HtmlString(e(__('No VAT')).'  ·  '.e(__('Total')).' '.Currency::format(round((float) $get('amount'), 2)));
            }

            $kept = self::kept((float) $get('amount'), (bool) $get('is_vat_included'), $record);
            $split = $kept ?? Vat::split((float) $get('amount'), (bool) $get('is_vat_included'));

            // The amounts as the app shows money (the dirham sign is markup); the words escaped.
            return new HtmlString(e(__('Basic')).' '.Currency::format($split['basic']).'  ·  '.e(__('VAT')).' '.Currency::format($split['vat']).'  ·  '.e(__('Total')).' '.Currency::format($split['total']));
        })->color('gray');
    }

    /**
     * What is saved of a fee as entered: its basic amount, its VAT, whether it was entered VAT included and whether
     * VAT does not apply to it (then none: its amount its total) — or, a fee whose amount and VAT choices were left
     * as they were, what it already is (a fee of before, kept without VAT, keeps it so when only its date or note
     * changes).
     *
     * @param  array<string, mixed>  $data  amount, is_vat_included, is_vat_exempt, and type when it is chosen
     * @return array{amount: float, vat: float, is_vat_included: bool, is_vat_exempt: bool}
     */
    public static function attributes(array $data, ?Fee $fee = null): array
    {
        $type = FeeType::of($data['type'] ?? null) ?? $fee?->type;
        $exempt = (bool) ($data['is_vat_exempt'] ?? false);
        $included = ! $exempt && (bool) ($data['is_vat_included'] ?? false);
        $amount = (float) ($data['amount'] ?? 0);

        if (! $type?->carriesVat() || $exempt) {
            return ['amount' => $amount, 'vat' => 0.0, 'is_vat_included' => false, 'is_vat_exempt' => $exempt && (bool) $type?->carriesVat()];
        }

        if ($fee !== null && $fee->type === $type && ($kept = self::kept($amount, $included, $fee)) !== null) {
            return ['amount' => $kept['basic'], 'vat' => $kept['vat'], 'is_vat_included' => $included, 'is_vat_exempt' => false];
        }

        $split = Vat::split($amount, $included);

        return ['amount' => $split['basic'], 'vat' => $split['vat'], 'is_vat_included' => $included, 'is_vat_exempt' => false];
    }

    /**
     * A fee's amount and VAT choices as its form shows them: the total when it was entered VAT included.
     *
     * @return array{amount: float, is_vat_included: bool, is_vat_exempt: bool}
     */
    public static function fill(Fee $fee): array
    {
        return ['amount' => $fee->entered_amount, 'is_vat_included' => (bool) $fee->is_vat_included, 'is_vat_exempt' => (bool) $fee->is_vat_exempt];
    }

    /**
     * The fee as it is kept, when what is typed is what it was: its basic, VAT and total.
     *
     * @return array{basic: float, vat: float, total: float}|null
     */
    private static function kept(float $amount, bool $included, ?Fee $fee): ?array
    {
        if ($fee === null || ! $fee->exists || (bool) $fee->is_vat_exempt || $included !== (bool) $fee->is_vat_included || round($amount, 2) !== round($fee->entered_amount, 2)) {
            return null;
        }

        return ['basic' => (float) $fee->amount, 'vat' => (float) $fee->vat, 'total' => $fee->total];
    }

    private static function fee(mixed $record): ?Fee
    {
        return $record instanceof Fee ? $record : null;
    }

    private static function carriesVat(mixed $type): bool
    {
        return FeeType::of($type)?->carriesVat() ?? false;
    }

    private static function includedByDefault(mixed $type): bool
    {
        return FeeType::of($type)?->vatIncludedByDefault() ?? false;
    }
}
