<?php

namespace App\Services\MMS\Requests;

use App\Enums\RequestStatus;
use App\Models\MatterRequest;
use App\Models\User;
use ArrayObject;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What happened to a request, step by step — submitted, changes requested, resubmitted, approved or
 * rejected — each with who, when and what they wrote. Read from the activity log (every change to a
 * request is kept there), so requests made before this was shown have their history too; one with
 * nothing logged is told from what it holds now.
 */
class RequestHistory
{
    public const SUBMITTED = 'submitted';

    public const CHANGES_REQUESTED = 'changes_requested';

    public const RESUBMITTED = 'resubmitted';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const DISPUTED = 'disputed';

    public const REOPENED = 'reopened';

    /**
     * Oldest first.
     *
     * @return list<array{step: string, label: string, color: string, icon: string, at: ?Carbon, by: ?string, comment: ?string}>
     */
    public static function for(MatterRequest $request): array
    {
        // Read once a request (the page asks again as it draws), and again once the request has changed.
        self::preload(collect([$request]));

        return self::known()[self::key($request)];
    }

    /**
     * Many requests' histories read in one go — a matter's — so each asks no more of the database.
     *
     * @param  Collection<int, MatterRequest>  $requests
     */
    public static function preload(Collection $requests): void
    {
        $missing = $requests->reject(fn (MatterRequest $request): bool => isset(self::known()[self::key($request)]));
        if ($missing->isEmpty()) {
            return;
        }

        $rows = self::rows($missing);
        $steps = $missing->mapWithKeys(fn (MatterRequest $request): array => [self::key($request) => ($found = $rows->get($request->getKey(), collect()))->isEmpty()
            ? self::fromRequest($request)
            : self::fromLog($found, $request)]);
        // Whoever took each step, all of them in one read.
        $names = User::query()->whereIn('id', $steps->flatten(1)->pluck('by')->filter()->unique()->values())->get()->keyBy('id');

        foreach ($steps as $key => $list) {
            self::known()[$key] = self::named($list, $names);
        }
    }

    /**
     * @param  list<array{step: string, at: ?Carbon, by: ?int, comment: ?string}>  $steps
     * @param  Collection<int, User>  $names
     * @return list<array{step: string, label: string, color: string, icon: string, at: ?Carbon, by: ?string, comment: ?string}>
     */
    private static function named(array $steps, Collection $names): array
    {
        return collect($steps)->map(fn (array $step): array => [
            ...$step,
            'label' => self::label($step['step']),
            'color' => self::color($step['step']),
            'icon' => self::icon($step['step']),
            'by' => $step['by'] ? ($names->get($step['by'])?->display_name ?? $names->get($step['by'])?->name) : null,
        ])->all();
    }

    /**
     * Their logged creation and changes, by request id, oldest first.
     *
     * @param  Collection<int, MatterRequest>  $requests
     * @return Collection<int, Collection<int, object>>
     */
    private static function rows(Collection $requests): Collection
    {
        return DB::table('activity_log')
            ->where('subject_type', (new MatterRequest)->getMorphClass())
            ->whereIn('subject_id', $requests->map->getKey()->all())
            ->whereIn('event', ['created', 'updated'])
            ->orderBy('id')
            ->get(['subject_id', 'event', 'causer_id', 'properties', 'attribute_changes', 'created_at'])
            ->groupBy('subject_id');
    }

    /** The histories read in this request (kept with the app, so each request starts afresh). */
    private static function known(): ArrayObject
    {
        $key = self::class.'.known';

        if (! app()->bound($key)) {
            app()->instance($key, new ArrayObject);
        }

        return app($key);
    }

    /** A request as it is now: read again once it changed. */
    private static function key(MatterRequest $request): string
    {
        return $request->getKey().'|'.$request->updated_at?->format('U.u').'|'.$request->status?->value;
    }

    /**
     * Each status the log saw it change to, as a step.
     *
     * @param  Collection<int, object>  $rows
     * @return list<array{step: string, at: ?Carbon, by: ?int, comment: ?string}>
     */
    private static function fromLog(Collection $rows, MatterRequest $request): array
    {
        $steps = [];

        foreach ($rows as $row) {
            // Kept as "attribute_changes" now; "properties" by the older log.
            $changes = json_decode((string) ($row->attribute_changes ?: $row->properties), true) ?: [];
            $new = (array) ($changes['attributes'] ?? []);
            $old = (array) ($changes['old'] ?? []);
            $at = Carbon::parse($row->created_at);

            if ($row->event === 'created') {
                $steps[] = ['step' => self::SUBMITTED, 'at' => $at, 'by' => self::id($new['request_by'] ?? $row->causer_id), 'comment' => self::text($new['comment'] ?? null)];

                continue;
            }

            $to = $new['status'] ?? null;
            $from = $old['status'] ?? null;
            if ($to === null || $to === $from) {
                continue;
            }

            $steps[] = match ($to) {
                RequestStatus::NEEDS_CHANGES->value => ['step' => self::CHANGES_REQUESTED, 'at' => $at, 'by' => self::id($new['approved_by'] ?? $row->causer_id), 'comment' => self::text($new['approved_comment'] ?? null)],
                RequestStatus::APPROVED->value => ['step' => self::APPROVED, 'at' => $at, 'by' => self::id($new['approved_by'] ?? $row->causer_id), 'comment' => self::text($new['approved_comment'] ?? null)],
                RequestStatus::REJECTED->value => ['step' => self::REJECTED, 'at' => $at, 'by' => self::id($new['approved_by'] ?? $row->causer_id), 'comment' => self::text($new['approved_comment'] ?? null)],
                RequestStatus::DISPUTED->value => ['step' => self::DISPUTED, 'at' => $at, 'by' => self::id($row->causer_id), 'comment' => null],
                // Back to pending: the corrected report sent again, or a decision undone.
                default => $from === RequestStatus::NEEDS_CHANGES->value
                    ? ['step' => self::RESUBMITTED, 'at' => $at, 'by' => self::id($row->causer_id ?? $request->request_by), 'comment' => ($new['comment'] ?? null) !== ($old['comment'] ?? null) ? self::text($new['comment'] ?? null) : null]
                    : ['step' => self::REOPENED, 'at' => $at, 'by' => self::id($row->causer_id), 'comment' => null],
            };
        }

        return $steps;
    }

    /**
     * Nothing logged: submitted, each round of changes it kept, and where it stands.
     *
     * @return list<array{step: string, at: ?Carbon, by: ?int, comment: ?string}>
     */
    private static function fromRequest(MatterRequest $request): array
    {
        $steps = [['step' => self::SUBMITTED, 'at' => $request->created_at, 'by' => $request->request_by, 'comment' => self::text($request->comment)]];

        foreach ((array) ($request->extra['rounds'] ?? []) as $round) {
            $steps[] = ['step' => self::CHANGES_REQUESTED, 'at' => filled($round['asked_at'] ?? null) ? Carbon::parse($round['asked_at']) : null, 'by' => self::id($round['asked_by'] ?? null), 'comment' => self::text($round['reason'] ?? null)];
            $steps[] = ['step' => self::RESUBMITTED, 'at' => null, 'by' => $request->request_by, 'comment' => null];
        }

        $now = match ($request->status) {
            RequestStatus::NEEDS_CHANGES => self::CHANGES_REQUESTED,
            RequestStatus::APPROVED => self::APPROVED,
            RequestStatus::REJECTED => self::REJECTED,
            RequestStatus::DISPUTED => self::DISPUTED,
            default => null,
        };
        if ($now !== null) {
            $steps[] = ['step' => $now, 'at' => $request->approved_at, 'by' => $request->approved_by, 'comment' => self::text($request->approved_comment)];
        }

        return $steps;
    }

    public static function label(string $step): string
    {
        return match ($step) {
            self::SUBMITTED => __('Request submitted'),
            self::CHANGES_REQUESTED => __('Changes requested'),
            self::RESUBMITTED => __('Resubmitted'),
            self::APPROVED => __('Approved'),
            self::REJECTED => __('Rejected'),
            self::DISPUTED => __('Disputed'),
            default => __('Reopened'),
        };
    }

    private static function color(string $step): string
    {
        return match ($step) {
            self::APPROVED => 'success',
            self::REJECTED => 'danger',
            self::CHANGES_REQUESTED => 'warning',
            self::DISPUTED => 'info',
            default => 'gray',
        };
    }

    private static function icon(string $step): string
    {
        return match ($step) {
            self::SUBMITTED => 'heroicon-m-paper-airplane',
            self::CHANGES_REQUESTED => 'heroicon-m-pencil-square',
            self::RESUBMITTED => 'heroicon-m-arrow-path',
            self::APPROVED => 'heroicon-m-check-circle',
            self::REJECTED => 'heroicon-m-x-circle',
            self::DISPUTED => 'heroicon-m-scale',
            default => 'heroicon-m-arrow-uturn-left',
        };
    }

    private static function id(mixed $id): ?int
    {
        return filled($id) ? (int) $id : null;
    }

    private static function text(mixed $text): ?string
    {
        return filled($text) ? trim((string) $text) : null;
    }
}
