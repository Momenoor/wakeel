<?php

namespace App\Services\MMS;

use App\Enums\FeeType;
use App\Models\Allocation;
use App\Models\Fee;
use App\Models\IncentiveAssistantLine;
use App\Models\IncentiveLine;
use App\Models\MarketingCommission;
use App\Models\Matter;
use App\Models\MatterParty;
use App\Models\Setting;
use App\Support\ActivityLog;
use App\Support\Currency;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Throwable;

/**
 * What a matter's marketers earn.
 *
 * Each of a matter's marketers has a share of their own: a percentage of its fees' basic amount (Settings →
 * Incentive: 25 by default, when none is typed for them) or a fixed amount. A third party's comes off first; a staff
 * marketer's percentage is of what is left after the third parties' (shares()). They earn it as the client pays: on
 * what is collected of the fees' basic amount (never more than the fees now come to — a credit note takes back), a
 * fixed amount in the part collected. What each has earned in all is worked out again whenever it may change
 * (sync()) and the difference kept as a commission row — so what was paid stays as it was paid, and what changes
 * after is settled next.
 *
 * A staff marketer is paid in the incentive calculation of its period (apply(): a line of their own there); an
 * external one is owed until marked paid (markPaid()). On the assistants' side (IncentiveCalculatorService): an
 * external marketer's share comes off the fee before the assistants' incentive is worked on it, and an assistant
 * who marketed the matter themself is paid their usual share and some points of the fee more (selfBonus()); one on
 * a matter someone else marketed, their usual share.
 */
class MarketingCommissions
{
    /** Settings → Incentive: the marketing share, a percentage of the fees' basic amount. */
    public const SHARE_SETTING = 'marketing_share_pct';

    public const DEFAULT_SHARE = 25.0;

    /** Settings → Incentive: the points of the fee added to the share of an assistant who marketed the matter themself. */
    public const SELF_BONUS_SETTING = 'incentive_bonus_when_marketed_by_self';

    public const DEFAULT_SELF_BONUS = 5.0;

    public static function defaultShare(): float
    {
        return self::setting(self::SHARE_SETTING, self::DEFAULT_SHARE);
    }

    public static function selfBonus(): float
    {
        return self::setting(self::SELF_BONUS_SETTING, self::DEFAULT_SELF_BONUS);
    }

    private static function setting(string $key, float $default): float
    {
        try {
            return (float) Setting::get($key, $default);
        } catch (Throwable) {
            return $default;
        }
    }

    public static function kindOf(MatterParty $marketer): string
    {
        return $marketer->type === MarketingCommission::EXTERNAL ? MarketingCommission::EXTERNAL : MarketingCommission::STAFF;
    }

    /**
     * What each marketer's share comes to on so much of the matter's fees' basic amount, by party: their own
     * percentage of it (none typed: the setting's), or of their fixed amount the part that is of all the fees'
     * basic amount. The third parties' first; a staff marketer's percentage is of what is left after them.
     *
     * @param  Collection<int, MatterParty>  $marketers
     * @return Collection<int, array{kind: string, amount: float}>
     */
    public static function shares(Collection $marketers, float $basic, float $matterBasic): Collection
    {
        $basic = max(0.0, $basic);
        $of = fn (MatterParty $marketer, float $base): float => $marketer->marketing_fixed !== null
            ? ($matterBasic > 0 ? (float) $marketer->marketing_fixed * min(1.0, $basic / $matterBasic) : 0.0)
            : $base * (float) ($marketer->marketing_rate ?? self::defaultShare()) / 100;

        $external = $marketers->filter(fn (MatterParty $marketer): bool => self::kindOf($marketer) === MarketingCommission::EXTERNAL)
            ->keyBy('party_id')
            ->map(fn (MatterParty $marketer): float => $of($marketer, $basic));
        $left = max(0.0, $basic - (float) $external->sum());

        return $marketers->keyBy('party_id')->map(fn (MatterParty $marketer): array => [
            'kind' => self::kindOf($marketer),
            'amount' => round($external->has($marketer->party_id) ? $external[$marketer->party_id] : $of($marketer, $left), 2),
        ]);
    }

    /**
     * The third parties' commissions on so much of the fees' basic amount: what comes off it before the staff
     * marketers' and the assistants' (IncentiveCalculatorService).
     *
     * @param  Collection<int, MatterParty>  $marketers
     */
    public static function externalShareOf(Collection $marketers, float $basic, float $matterBasic): float
    {
        return round((float) self::shares($marketers, $basic, $matterBasic)->where('kind', MarketingCommission::EXTERNAL)->sum('amount'), 2);
    }

    /** The fees the share is on: the office's own — not a deduction, the VAT rows of before, nor the matter's expenses. */
    public static function isRevenue(Fee $fee): bool
    {
        return $fee->type === null || ! in_array($fee->type->value, FeeType::excludedFromIncentiveValues(), true);
    }

    /** A payment, a fee or a credit note changed on a matter: what its marketers earned, again. */
    public function afterChange(?Matter $matter, ?CarbonInterface $date = null, ?Allocation $payment = null): void
    {
        // sync() reads the marketers and the commissions itself and stops when there are none: asked here first,
        // that was two queries more on every fee and payment saved.
        if ($matter) {
            $this->sync($matter, $date, $payment);
        }
    }

    /**
     * A payment's date changed: what it earned is dated with it — while not paid yet (not marked paid, nor in a
     * final calculation); a draft's lines read it again when run.
     */
    public function redate(Allocation $payment): void
    {
        MarketingCommission::query()
            ->where('allocation_id', $payment->getKey())
            ->whereNull('paid_at')
            ->where(fn ($query) => $query->whereNull('incentive_calculation_id')
                ->orWhereHas('calculation', fn ($calculation) => $calculation->where('status', '!=', 'finalized')))
            ->update(['date' => $payment->date->toDateString()]);
    }

    /**
     * What each of the matter's marketers has earned in all, worked out again, and the difference kept — dated
     * $date (the payment's), or as the matter's latest payment, or (nothing paid) today.
     */
    public function sync(Matter $matter, ?CarbonInterface $date = null, ?Allocation $payment = null): void
    {
        $marketers = $matter->matterParties()->where('role', 'marketer')->get();
        $recorded = MarketingCommission::query()->where('matter_id', $matter->getKey())->get(['party_id', 'kind', 'amount'])->groupBy('party_id');

        if ($marketers->isEmpty() && $recorded->isEmpty()) {
            return;
        }

        $fees = $matter->fees()->with('allocations')->get()->filter(fn (Fee $fee): bool => self::isRevenue($fee));
        $matterBasic = (float) $fees->sum(fn (Fee $fee): float => max(0.0, (float) $fee->amount));
        // Of each fee, the basic part of what was paid on it — never more than its basic amount now.
        $collected = round((float) $fees->sum(function (Fee $fee): float {
            $basic = max(0.0, (float) $fee->amount);
            $paid = (float) $fee->allocations->sum('amount');

            return $fee->total > 0 ? min($basic, $paid * $basic / $fee->total) : 0.0;
        }), 2);
        $shares = self::shares($marketers, $collected, $matterBasic);
        $external = (float) $shares->where('kind', MarketingCommission::EXTERNAL)->sum('amount');
        // Not by a payment (a marketer added, a credit note …): dated as the latest payment it is earned on — so it
        // falls in the period the money came in, not after it; with nothing paid yet, today.
        $date ??= $fees->flatMap(fn (Fee $fee) => $fee->allocations)->max('date');

        foreach ($shares->keys()->merge($recorded->keys())->unique() as $partyId) {
            $share = $shares->get($partyId);
            $target = $share['amount'] ?? 0.0;
            $earned = round((float) $recorded->get($partyId, collect())->sum('amount'), 2);
            $amount = round($target - $earned, 2);

            if (abs($amount) < 0.005) {
                continue;
            }

            MarketingCommission::create([
                'matter_id' => $matter->getKey(),
                'party_id' => $partyId,
                // Earned by a payment: kept with it, dated with it (redate()).
                'allocation_id' => $payment?->getKey(),
                'kind' => $share['kind'] ?? $recorded->get($partyId)->first()->kind,
                'date' => ($date ?? now())->toDateString(),
                'base' => $collected,
                'amount' => $amount,
                'description' => $share === null
                    ? __('No longer a marketer of the matter: what was earned taken back.')
                    : $this->describe($marketers->firstWhere('party_id', $partyId), $collected, $matterBasic, $external, $target),
            ]);
        }
    }

    /** A marketer's share in words: their percentage, or their fixed amount. */
    public static function shareLabel(MatterParty $marketer): string
    {
        return $marketer->marketing_fixed !== null
            ? __('Marketing share :amount fixed', ['amount' => Currency::text((float) $marketer->marketing_fixed)])
            : __('Marketing share :rate%', ['rate' => rtrim(rtrim(number_format((float) ($marketer->marketing_rate ?? self::defaultShare()), 2), '0'), '.')]);
    }

    /** What it came of, and what it makes in all. */
    private function describe(MatterParty $marketer, float $collected, float $matterBasic, float $external, float $target): string
    {
        $values = [
            'share' => self::shareLabel($marketer),
            'collected' => Currency::text($collected),
            'basic' => Currency::text($matterBasic),
            'external' => Currency::text($external),
            'target' => Currency::text($target),
        ];

        // A staff marketer's percentage: of what is left after the third parties' commissions.
        return self::kindOf($marketer) === MarketingCommission::STAFF && $marketer->marketing_fixed === null && $external > 0
            ? __(':share; collected :collected of the fees\' :basic, less the third parties\' :external: :target earned in all.', $values)
            : __(':share; collected :collected of the fees\' :basic: :target earned in all.', $values);
    }

    /**
     * A calculation run: its staff marketers' commissions — those of its period not paid in another calculation —
     * one line of the marketer's own for each matter (what each payment or change on it added, together), rebuilt
     * every run. A draft deleted gives them back (its id emptied).
     */
    public function apply(Model $calculation): void
    {
        ActivityLog::quietly(function () use ($calculation): void {
            IncentiveLine::query()->where('incentive_calculation_id', $calculation->getKey())->where('is_marketing', true)->delete();
            MarketingCommission::query()->where('incentive_calculation_id', $calculation->getKey())->update(['incentive_calculation_id' => null]);

            $pending = MarketingCommission::query()->staff()
                ->whereNull('incentive_calculation_id')
                ->whereDate('date', '<=', $calculation->period_end)
                ->orderBy('id')
                ->get();

            foreach ($pending->groupBy(fn (MarketingCommission $c): string => $c->party_id.'-'.$c->matter_id) as $commissions) {
                $amount = round((float) $commissions->sum('amount'), 2);
                // The latest says what it all comes to (its line's description: MarketingCommission::label()).
                $latest = $commissions->last();

                $line = IncentiveLine::create([
                    'incentive_calculation_id' => $calculation->getKey(),
                    'matter_id' => $latest->matter_id,
                    'is_marketing' => true,
                    'net_amount' => $amount,
                ]);

                IncentiveAssistantLine::create([
                    'incentive_line_id' => $line->getKey(),
                    'party_id' => $latest->party_id,
                    'marketing_commission_id' => $latest->getKey(),
                    'share_amount' => $amount,
                    'extra_percentage' => 0,
                    'extra_amount' => 0,
                    'minimum_penalty_pct' => 0,
                    'minimum_penalty_amount' => 0,
                    'total_amount' => $amount,
                ]);

                MarketingCommission::query()->whereKey($commissions->modelKeys())->update(['incentive_calculation_id' => $calculation->getKey()]);
            }
        });
    }

    /**
     * An external marketer paid what they are owed: these of their commissions marked paid on $on.
     *
     * @param  Collection<int, MarketingCommission>  $commissions
     */
    public function markPaid(Collection $commissions, CarbonInterface $on): int
    {
        $ids = $commissions->filter(fn (MarketingCommission $c): bool => $c->kind === MarketingCommission::EXTERNAL && $c->paid_at === null)->modelKeys();

        return MarketingCommission::query()->whereKey($ids)->update(['paid_at' => $on->toDateString(), 'paid_by' => auth()->id()]);
    }
}
