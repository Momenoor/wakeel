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
 * form's fees: "Including VAT" (on for the types entered VAT included, an expert fee — FeeType), the basic, VAT
 * and total as typed, and what is saved — split by App\Support\Vat, nowhere else.
 */
final class FeeVatFields
{
    /** "Including VAT": shown for the types VAT is charged on; set to the type's own default when it is chosen. */
    public static function toggle(): Toggle
    {
        return Toggle::make('is_vat_included')
            ->label(__('Including VAT'))
            ->default(fn (Get $get): bool => self::includedByDefault($get('type')))
            ->visible(fn (Get $get, $record = null): bool => self::carriesVat($get('type') ?? self::fee($record)?->type))
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

            $kept = self::kept((float) $get('amount'), (bool) $get('is_vat_included'), $record);
            $split = $kept ?? Vat::split((float) $get('amount'), (bool) $get('is_vat_included'));

            // The amounts as the app shows money (the dirham sign is markup); the words escaped.
            return new HtmlString(e(__('Basic')).' '.Currency::format($split['basic']).'  ·  '.e(__('VAT')).' '.Currency::format($split['vat']).'  ·  '.e(__('Total')).' '.Currency::format($split['total']));
        })->color('gray');
    }

    /**
     * What is saved of a fee as entered: its basic amount, its VAT and whether it was entered VAT included — or, a
     * fee whose amount and "Including VAT" were left as they were, what it already is (a fee of before, kept
     * without VAT, keeps it so when only its date or note changes).
     *
     * @param  array<string, mixed>  $data  amount, is_vat_included, and type when it is chosen
     * @return array{amount: float, vat: float, is_vat_included: bool}
     */
    public static function attributes(array $data, ?Fee $fee = null): array
    {
        $type = FeeType::of($data['type'] ?? null) ?? $fee?->type;
        $included = (bool) ($data['is_vat_included'] ?? false);
        $amount = (float) ($data['amount'] ?? 0);

        if (! $type?->carriesVat()) {
            return ['amount' => $amount, 'vat' => 0.0, 'is_vat_included' => false];
        }

        if ($fee !== null && $fee->type === $type && ($kept = self::kept($amount, $included, $fee)) !== null) {
            return ['amount' => $kept['basic'], 'vat' => $kept['vat'], 'is_vat_included' => $included];
        }

        $split = Vat::split($amount, $included);

        return ['amount' => $split['basic'], 'vat' => $split['vat'], 'is_vat_included' => $included];
    }

    /**
     * A fee's amount and "Including VAT" as its form shows them: the total when it was entered VAT included.
     *
     * @return array{amount: float, is_vat_included: bool}
     */
    public static function fill(Fee $fee): array
    {
        return ['amount' => $fee->entered_amount, 'is_vat_included' => (bool) $fee->is_vat_included];
    }

    /**
     * The fee as it is kept, when what is typed is what it was: its basic, VAT and total.
     *
     * @return array{basic: float, vat: float, total: float}|null
     */
    private static function kept(float $amount, bool $included, ?Fee $fee): ?array
    {
        if ($fee === null || ! $fee->exists || $included !== (bool) $fee->is_vat_included || round($amount, 2) !== round($fee->entered_amount, 2)) {
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
