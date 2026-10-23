<?php

namespace App\Services\MMS;

use App\Enums\NotificationType;
use App\Filament\Mms\Resources\Matters\MatterResource;
use App\Models\Allocation;
use App\Models\Fee;
use App\Models\Matter;
use App\Models\User;
use App\Services\Notifications\Notifier;
use App\Support\Currency;
use App\Support\Dates;
use App\Support\PendingBatch;
use ArrayObject;
use Carbon\Carbon;
use Closure;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Whoever is to hear of it (Notification settings, "A fee or a collection was added, changed or deleted":
 * on for super admins and accountants, any role can be given it) is told when someone adds, changes or
 * deletes a fee or a collection. What one action did to a matter's fees comes as one notification — a
 * collection that moved its fee's date with it, too — sent once the request is done.
 *
 * Only what a person does: not the system's own work (imports, jobs) nor the fee data maintenance and a
 * matter deleted for good, which run quietly().
 */
class FeeChangeNotifier
{
    private static int $quiet = 0;

    /** Done without telling anyone. */
    public static function quietly(Closure $callback): mixed
    {
        self::$quiet++;

        try {
            return $callback();
        } finally {
            self::$quiet--;
        }
    }

    public static function created(Model $model): void
    {
        self::note($model, $model instanceof Fee
            ? __('Fee added: :fee', ['fee' => self::fee($model)])
            : __('Collection recorded: :amount on the :type fee (:date)', ['amount' => Currency::text($model->amount), 'type' => self::type($model->fee), 'date' => $model->date?->format(Dates::dateFormat())]));
    }

    public static function updated(Model $model): void
    {
        $changes = self::changes($model);
        if ($changes === []) {
            return;
        }

        self::note($model, $model instanceof Fee
            ? __('Fee changed: :type — :changes', ['type' => self::type($model), 'changes' => implode('; ', $changes)])
            : __('Collection on the :type fee changed — :changes', ['type' => self::type($model->fee), 'changes' => implode('; ', $changes)]));
    }

    public static function deleted(Model $model): void
    {
        self::note($model, $model instanceof Fee
            ? __('Fee deleted: :fee', ['fee' => self::fee($model)])
            : __('Collection deleted: :amount on the :type fee', ['amount' => Currency::text($model->amount), 'type' => self::type($model->fee)]));
    }

    /** What was gathered, one notification a matter. Run once the request is done; by hand in tests. */
    public static function flush(): void
    {
        $pending = self::pending()->getArrayCopy();
        self::pending()->exchangeArray([]);

        foreach ($pending as $matterId => $change) {
            $matter = Matter::find($matterId);
            $by = User::find($change['by']);
            if (! $matter || ! $by) {
                continue;
            }

            try {
                Notifier::send(
                    NotificationType::FEES_CHANGED,
                    User::query()->whereKeyNot($by->getKey())->get(),
                    __(':user changed the fees of matter :matter', ['user' => $by->display_name ?: $by->name, 'matter' => $matter->year.'/'.$matter->number]),
                    implode("\n", array_unique($change['lines'])),
                    MatterResource::getUrl('view', ['record' => $matter], panel: 'mms').'?tab=fees',
                    fn (Notification $notification) => $notification->icon('heroicon-o-banknotes')->iconColor('warning'),
                );
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    private static function note(Model $model, string $line): void
    {
        $matterId = (int) ($model->matter_id ?: ($model instanceof Allocation ? $model->fee?->matter_id : null));
        if (self::$quiet > 0 || ! auth()->check() || $matterId === 0) {
            return;
        }

        $change = self::pending()[$matterId] ?? ['by' => (int) auth()->id(), 'lines' => []];
        $change['lines'][] = $line;
        self::pending()[$matterId] = $change;
    }

    /**
     * What this request did, by matter (who and what) — kept with the app, so each request starts afresh;
     * told once it is done.
     *
     * @return ArrayObject<int, array{by: int, lines: list<string>}>
     */
    private static function pending(): ArrayObject
    {
        return PendingBatch::for(self::class, fn () => self::flush());
    }

    /**
     * What changed that someone would want to know: the amount, the date, the type, the description —
     * not its status, which follows what was collected.
     *
     * @return list<string>
     */
    private static function changes(Model $model): array
    {
        $changes = [];

        foreach (['type' => __('Type'), 'amount' => __('Amount'), 'date' => __('Date'), 'description' => __('Description')] as $field => $label) {
            if (! $model->wasChanged($field)) {
                continue;
            }

            $old = $model->getOriginal($field);
            $new = $model->getAttribute($field);
            $show = fn ($value): string => match ($field) {
                'amount' => Currency::text($value),
                'date' => $value ? Carbon::parse($value)->format(Dates::dateFormat()) : '—',
                'type' => $value?->getLabel() ?? '—',
                default => filled($value) ? (string) $value : '—',
            };

            if ($show($old) !== $show($new)) {
                $changes[] = $label.': '.__(':old → :new', ['old' => $show($old), 'new' => $show($new)]);
            }
        }

        return $changes;
    }

    private static function fee(Fee $fee): string
    {
        return self::type($fee).' '.Currency::text($fee->amount).' ('.$fee->date?->format(Dates::dateFormat()).')';
    }

    private static function type(?Fee $fee): string
    {
        return (string) ($fee?->type?->getLabel() ?? __('Fee'));
    }
}
