<?php

namespace App\Services\MMS;

use App\Enums\ProgressType;
use App\Models\BulkMailCampaign;
use App\Models\Matter;
use App\Models\MatterEmail;
use App\Models\MatterLetter;
use App\Models\MatterMinutes;
use App\Models\MatterProgress;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * A matter's progress, as it happens: a letter issued or sent, a meeting
 * held (its minutes finalised) and its minutes sent, an email to the
 * parties, the initial and final reports' dates. Each kept with what it
 * came from — recorded again (a letter re-issued, minutes finalised
 * again, a report's date changed), the same step is updated, not added.
 * A failure here never stops what it records.
 */
class MatterProgressRecorder
{
    /** A way something was sent — email, WhatsApp. */
    public const EMAIL = 'email';

    public const WHATSAPP = 'whatsapp';

    /**
     * A letter sent (issuing alone is no step): one step a send, saying how
     * and to whom.
     *
     * @param  list<string>  $names  whom it reached
     * @param  list<string>  $methods  self::EMAIL, self::WHATSAPP
     */
    public static function letterSent(MatterLetter $letter, array $names, ?int $userId = null, ?CarbonInterface $at = null, array $methods = [self::EMAIL]): void
    {
        self::add($letter->matter_id, ProgressType::LETTER_SENT, $letter,
            (string) ($letter->subject ?: $letter->reference), $at ?? now(),
            self::join([self::methods($methods), $letter->reference, self::names($names)], ' — '), $userId);
    }

    public static function meetingHeld(MatterMinutes $minutes, ?int $userId = null): void
    {
        $present = collect($minutes->attendees ?? [])->filter(fn ($a) => is_array($a) && ! empty($a['present']))->pluck('name')->all();

        self::keep($minutes->matter_id, ProgressType::MEETING_HELD, $minutes,
            __('Minutes no. :number', ['number' => $minutes->number]),
            $minutes->meeting_at ?? $minutes->finalized_at ?? now(),
            self::names($present), $userId);
    }

    /**
     * Minutes sent — by email and WhatsApp alike, one step a send, saying
     * how and to whom.
     *
     * @param  list<string>  $names  whom they reached
     * @param  list<string>  $methods  self::EMAIL, self::WHATSAPP
     */
    public static function minutesSent(MatterMinutes $minutes, array $names, ?int $userId = null, ?CarbonInterface $at = null, array $methods = []): void
    {
        self::add($minutes->matter_id, ProgressType::MINUTES_SENT, $minutes,
            __('Minutes no. :number', ['number' => $minutes->number]), $at ?? now(),
            self::join([self::methods($methods), self::names($names)], ' — '), $userId);
    }

    /**
     * How it went: "By email and WhatsApp".
     *
     * @param  list<string>  $methods
     */
    public static function methods(array $methods): ?string
    {
        $methods = array_values(array_intersect([self::EMAIL, self::WHATSAPP], $methods));

        return match ($methods) {
            [self::EMAIL, self::WHATSAPP] => __('By email and WhatsApp'),
            [self::EMAIL] => __('By email'),
            [self::WHATSAPP] => __('By WhatsApp'),
            default => null,
        };
    }

    /** A reply to one of the matter's emails, collected from the mailbox. */
    public static function replyReceived(MatterEmail $reply): void
    {
        self::keep($reply->matter_id, ProgressType::REPLY_RECEIVED, $reply,
            $reply->subject !== '' ? $reply->subject : __('(no subject)'),
            $reply->at ?? now(),
            self::join([$reply->from, $reply->parent ? __('In reply to: :subject', ['subject' => $reply->parent->subject]) : null], ' — '),
            $reply->parent?->user_id);
    }

    /** An email to the matter's parties (Bulk Mail): one step, however many it reaches. */
    public static function emailSent(BulkMailCampaign $campaign, ?CarbonInterface $at = null): void
    {
        if (blank($campaign->matter_id)) {
            return;
        }

        $existing = self::find($campaign->matter_id, ProgressType::EMAIL_SENT, $campaign);

        self::keep($campaign->matter_id, ProgressType::EMAIL_SENT, $campaign,
            (string) ($campaign->subject ?: $campaign->name),
            $existing?->happened_at ?? $at ?? now(),
            __('To :count recipients', ['count' => $campaign->sent_count]),
            $campaign->created_by);
    }

    /**
     * The initial or final report's date as the matter now has it: its step
     * kept on that date, or gone with it.
     */
    public static function reports(Matter $matter): void
    {
        foreach (['initial_report_at' => ProgressType::INITIAL_REPORT, 'final_report_at' => ProgressType::FINAL_REPORT] as $column => $type) {
            $date = $matter->getAttribute($column);

            if ($date) {
                self::keep($matter->getKey(), $type, $matter, $type->getLabel(), $date, null, auth()->id());
            } else {
                self::find($matter->getKey(), $type, $matter)?->delete();
            }
        }
    }

    /**
     * The progress so far of every matter, from what Wakeel has kept: its
     * letters sent, minutes finalised and sent, emails to the parties,
     * reports' dates. Run again, nothing is added twice.
     *
     * @return array<string, int> steps recorded, by type
     */
    public static function backfill(): array
    {
        $before = MatterProgress::query()->selectRaw('type, count(*) as n')->groupBy('type')->pluck('n', 'type')->all();
        $has = fn (ProgressType $type, Model $source): bool => self::find((int) $source->getAttribute('matter_id'), $type, $source) !== null;

        MatterLetter::query()->whereNotNull('matter_id')->with('recipients')->chunkById(200, function ($letters) use ($has) {
            foreach ($letters as $letter) {
                if ($letter->sent_at && ! $has(ProgressType::LETTER_SENT, $letter)) {
                    $sent = $letter->recipients->filter(fn ($r) => $r->delivered_at !== null)->pluck('name')->all();
                    self::letterSent($letter, $sent, $letter->sent_by, $letter->sent_at);
                }
            }
        });

        MatterMinutes::query()->where('status', MatterMinutes::FINAL)->chunkById(200, function ($all) {
            foreach ($all as $minutes) {
                self::meetingHeld($minutes);
            }
        });

        // Each day's sending of the minutes, one step.
        MatterMinutes::query()->whereHas('deliveries', fn ($q) => $q->whereNotNull('sent_at'))->with('deliveries')->chunkById(200, function ($all) use ($has) {
            foreach ($all as $minutes) {
                if ($has(ProgressType::MINUTES_SENT, $minutes)) {
                    continue;
                }

                $minutes->deliveries->whereNotNull('sent_at')->sortBy('sent_at')
                    ->groupBy(fn ($d) => $d->sent_at->toDateString())
                    ->each(fn ($day) => self::minutesSent($minutes, $day->pluck('name')->unique()->values()->all(), $day->first()->sent_by, $day->first()->sent_at, $day->pluck('channel')->unique()->values()->all()));
            }
        });

        BulkMailCampaign::query()->whereNotNull('matter_id')->where('sent_count', '>', 0)->chunkById(200, function ($campaigns) {
            foreach ($campaigns as $campaign) {
                $first = $campaign->recipients()->whereNotNull('sent_at')->min('sent_at');
                self::emailSent($campaign, $first ? Carbon::parse($first) : $campaign->created_at);
            }
        });

        Matter::withTrashed()->where(fn ($q) => $q->whereNotNull('initial_report_at')->orWhereNotNull('final_report_at'))
            ->chunkById(200, function ($matters) {
                foreach ($matters as $matter) {
                    self::reports($matter);
                }
            });

        $after = MatterProgress::query()->selectRaw('type, count(*) as n')->groupBy('type')->pluck('n', 'type')->all();

        $added = [];
        foreach ($after as $type => $n) {
            if ($n > ($before[$type] ?? 0)) {
                $added[$type] = $n - ($before[$type] ?? 0);
            }
        }

        return $added;
    }

    /** The one step of this type from this source, kept up to date. */
    private static function keep(?int $matterId, ProgressType $type, Model $source, string $title, CarbonInterface $at, ?string $details, ?int $userId): void
    {
        self::safely(function () use ($matterId, $type, $source, $title, $at, $details, $userId) {
            if (! $matterId) {
                return;
            }

            $step = self::find($matterId, $type, $source) ?? new MatterProgress([
                'matter_id' => $matterId,
                'type' => $type,
                'source_type' => $source->getMorphClass(),
                'source_id' => $source->getKey(),
                'user_id' => $userId,
            ]);

            $step->fill(['title' => $title, 'happened_at' => $at, 'details' => $details])->save();
        });
    }

    /** A step each time (a letter sent again is sent again). */
    private static function add(?int $matterId, ProgressType $type, Model $source, string $title, CarbonInterface $at, ?string $details, ?int $userId): void
    {
        self::safely(function () use ($matterId, $type, $source, $title, $at, $details, $userId) {
            if ($matterId) {
                MatterProgress::create([
                    'matter_id' => $matterId,
                    'type' => $type,
                    'title' => $title,
                    'happened_at' => $at,
                    'details' => $details,
                    'source_type' => $source->getMorphClass(),
                    'source_id' => $source->getKey(),
                    'user_id' => $userId,
                ]);
            }
        });
    }

    private static function find(int $matterId, ProgressType $type, Model $source): ?MatterProgress
    {
        return MatterProgress::query()
            ->where('matter_id', $matterId)
            ->where('type', $type)
            ->where('source_type', $source->getMorphClass())
            ->where('source_id', $source->getKey())
            ->first();
    }

    private static function safely(callable $record): void
    {
        try {
            $record();
        } catch (\Throwable $e) {
            report($e);
            Log::warning('Matter progress not recorded: '.$e->getMessage());
        }
    }

    /**
     * @param  list<?string>  $names
     */
    private static function names(array $names): ?string
    {
        return self::join(array_values(array_unique(array_map(fn ($n) => trim((string) $n), $names))), '، ');
    }

    /**
     * @param  list<?string>  $parts
     */
    private static function join(array $parts, string $glue): ?string
    {
        $parts = array_values(array_filter($parts, fn ($p) => filled($p)));

        return $parts === [] ? null : mb_substr(implode($glue, $parts), 0, 2000);
    }
}
