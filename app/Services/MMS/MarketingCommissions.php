<?php

namespace App\Services\MMS;

use App\Enums\FeeType;
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
 * A matter's marketing share is a percentage of its fees' basic amount (Settings → Incentive: 25 by default,
 * changeable on the matter) or a fixed amount typed on it; its marketers split it — equally, or in the parts typed
 * for them. They earn it as the client pays: on what is collected of the fees' basic amount (never more than the
 * fees now come to — a credit note takes back), a fixed amount in the part collected. What each has earned in all
 * is worked out again whenever it may change (sync()) and the difference kept as a commission row — so what was
 * paid stays as it was paid, and what changes after is settled next.
 *
 * A staff marketer is paid in the incentive calculation of its period (apply(): a line of their own there); an
 * external one is owed until marked paid (markPaid()). On the assistants' side (IncentiveCalculatorService): an
 * external marketer's share comes off the fee before the assistants' incentive is worked on it, and an assistant
 * on a matter someone else marketed is paid a flat rate (otherRate()), not the matter's.
 */
class MarketingCommissions
{
    /** Settings → Incentive: the marketing share, a percentage of the fees' basic amount. */
    public const SHARE_SETTING = 'marketing_share_pct';

    public const DEFAULT_SHARE = 25.0;

    /** Settings → Incentive: an assistant's flat rate on a matter someone else marketed. */
    public const OTHER_RATE_SETTING = 'incentive_rate_when_marketed_by_other';

    public const DEFAULT_OTHER_RATE = 10.0;

    public static function defaultShare(): float
    {
        return self::setting(self::SHARE_SETTING, self::DEFAULT_SHARE);
    }

    public static function otherRate(): float
    {
        return self::setting(self::OTHER_RATE_SETTING, self::DEFAULT_OTHER_RATE);
    }

    private static function setting(string $key, float $default): float
    {
        try {
            return (float) Setting::get($key, $default);
        } catch (Throwable) {
            return $default;
        }
    }

    /**
     * Each marketer's kind and part of the share (a fraction), by party: the parts typed, as parts of their sum;
     * none typed, equal.
     *
     * @param  Collection<int, MatterParty>  $marketers
     * @return Collection<int, array{kind: string, part: float}>
     */
    public static function parts(Collection $marketers): Collection
    {
        $typed = (float) $marketers->sum(fn (MatterParty $marketer): float => (float) ($marketer->marketing_part ?? 0));

        return $marketers->keyBy('party_id')->map(fn (MatterParty $marketer): array => [
            'kind' => $marketer->type === MarketingCommission::EXTERNAL ? MarketingCommission::EXTERNAL : MarketingCommission::STAFF,
            'part' => $typed > 0 ? (float) ($marketer->marketing_part ?? 0) / $typed : 1 / max(1, $marketers->count()),
        ]);
    }

    /**
     * The marketing share on so much of the matter's fees' basic amount: its rate of it, or of its fixed amount the
     * part that is of all its fees' basic amount.
     */
    public static function shareOf(Matter $matter, float $basic, float $matterBasic): float
    {
        if ($matter->marketing_fixed !== null) {
            return $matterBasic > 0 ? (float) $matter->marketing_fixed * min(1.0, max(0.0, $basic) / $matterBasic) : 0.0;
        }

        return max(0.0, $basic) * (float) ($matter->marketing_rate ?? self::defaultShare()) / 100;
    }

    /** The fees the share is on: the office's own — not a deduction, the VAT rows of before, nor the matter's expenses. */
    public static function isRevenue(Fee $fee): bool
    {
        return $fee->type === null || ! in_array($fee->type->value, FeeType::excludedFromIncentiveValues(), true);
    }

    /** A payment, a fee or a credit note changed on a matter: what its marketers earned, again. */
    public function afterChange(?Matter $matter, ?CarbonInterface $date = null): void
    {
        if ($matter && ($matter->matterParties()->where('role', 'marketer')->exists() || MarketingCommission::query()->where('matter_id', $matter->getKey())->exists())) {
            $this->sync($matter, $date);
        }
    }

    /**
     * What each of the matter's marketers has earned in all, worked out again, and the difference kept — dated
     * $date (the payment's), or as the matter's latest payment, or (nothing paid) today.
     */
    public function sync(Matter $matter, ?CarbonInterface $date = null): void
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
        $share = self::shareOf($matter, $collected, $matterBasic);
        // Not by a payment (a marketer added, a credit note …): dated as the latest payment it is earned on — so it
        // falls in the period the money came in, not after it; with nothing paid yet, today.
        $date ??= $fees->flatMap(fn (Fee $fee) => $fee->allocations)->max('date');
        $parts = self::parts($marketers);

        foreach ($parts->keys()->merge($recorded->keys())->unique() as $partyId) {
            $part = $parts->get($partyId);
            $target = round($share * ($part['part'] ?? 0.0), 2);
            $earned = round((float) $recorded->get($partyId, collect())->sum('amount'), 2);
            $amount = round($target - $earned, 2);

            if (abs($amount) < 0.005) {
                continue;
            }

            MarketingCommission::create([
                'matter_id' => $matter->getKey(),
                'party_id' => $partyId,
                'kind' => $part['kind'] ?? $recorded->get($partyId)->first()->kind,
                'date' => ($date ?? now())->toDateString(),
                'base' => $collected,
                'amount' => $amount,
                'description' => $part === null
                    ? __('No longer a marketer of the matter: what was earned taken back.')
                    : $this->describe($matter, $collected, $matterBasic, $part['part'], $target),
            ]);
        }
    }

    /** What it came of, and what it makes in all. */
    private function describe(Matter $matter, float $collected, float $matterBasic, float $part, float $target): string
    {
        return __(':share; collected :collected of the fees\' :basic; :part% of it: :target earned in all.', [
            'share' => $matter->marketing_fixed !== null
                ? __('Marketing share :amount fixed', ['amount' => Currency::text((float) $matter->marketing_fixed)])
                : __('Marketing share :rate%', ['rate' => rtrim(rtrim(number_format((float) ($matter->marketing_rate ?? self::defaultShare()), 2), '0'), '.')]),
            'collected' => Currency::text($collected),
            'basic' => Currency::text($matterBasic),
            'part' => rtrim(rtrim(number_format($part * 100, 2), '0'), '.'),
            'target' => Currency::text($target),
        ]);
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
