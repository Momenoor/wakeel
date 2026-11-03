<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * A creditor of a matter — no party of it (a bankruptcy's): its claim, its phones, and the addresses it writes from,
 * its own or its lawyer's or agent's (MatterCreditorAddress). Their emails are filed in a folder of their own
 * (ReceivedEmailFiling::CREDITOR); they are on the matter's Contacts tab, and exported from there for bulk mail.
 */
class MatterCreditor extends Model
{
    protected $fillable = ['matter_id', 'number', 'name', 'id_number', 'phones', 'claim_amount', 'custom'];

    protected function casts(): array
    {
        return [
            'phones' => 'array',
            'claim_amount' => 'decimal:2',
            'custom' => 'array',
        ];
    }

    protected static function booted(): void
    {
        // Numbered as it is saved with no number — added, or edited with "No." left empty: its claim's serial in the
        // matter's list, after the last given, never twice. Those of the matter with none yet (from before the
        // numbers) are numbered first, as they were added, so this one follows them.
        static::saving(function (self $creditor): void {
            if ($creditor->number === null) {
                $creditor->number = static::numberUnnumbered((int) $creditor->matter_id, $creditor->getKey());
            }
        });
    }

    /**
     * The matter's creditors with no number given theirs, after the last one given, in the order they were added —
     * but the one being saved ($except), whose number is returned for it to write. The next number.
     */
    public static function numberUnnumbered(int $matterId, mixed $except = null): int
    {
        $next = ((int) static::query()->where('matter_id', $matterId)->max('number')) + 1;
        $ids = static::query()->where('matter_id', $matterId)->whereNull('number')
            ->when($except !== null, fn ($query) => $query->whereKeyNot($except))
            ->orderBy('id')->pluck('id');

        if ($ids->isNotEmpty()) {
            // All at once: each its number by its id.
            $cases = '';
            foreach ($ids as $id) {
                $cases .= ' WHEN '.(int) $id.' THEN '.$next++;
            }
            static::query()->whereKey($ids->all())->update(['number' => DB::raw("CASE id{$cases} END")]);
        }

        return $next;
    }

    /** Its number as a folder writes it: three digits (001), so the folders sort in order. */
    public function paddedNumber(): string
    {
        return $this->number !== null ? str_pad((string) $this->number, 3, '0', STR_PAD_LEFT) : '';
    }

    /** The value of one of its own columns (Type::creditorColumns()). */
    public function customValue(string $column): ?string
    {
        $value = ((array) $this->custom)[$column] ?? null;

        return filled($value) ? (string) $value : null;
    }

    /**
     * An address given to a creditor on a matter — a new one (named), or one it has: as the creditor's own, or as
     * its agent's. Taken from whichever creditor had it. The creditor.
     */
    public static function assign(Matter $matter, ?int $creditorId, ?string $name, string $email, bool $agent = false, ?string $agentName = null): self
    {
        return DB::transaction(function () use ($matter, $creditorId, $name, $email, $agent, $agentName): self {
            $creditor = $creditorId
                ? $matter->creditors()->findOrFail($creditorId)
                : $matter->creditors()->create(['name' => trim((string) $name)]);

            MatterCreditorAddress::query()->updateOrCreate(
                ['matter_id' => $matter->getKey(), 'email' => mb_strtolower(trim($email))],
                ['matter_creditor_id' => $creditor->getKey(), 'agent' => $agent, 'agent_name' => $agent ? (trim((string) $agentName) ?: null) : null],
            );

            return $creditor;
        });
    }

    /**
     * @return BelongsTo<Matter, $this>
     */
    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    /**
     * @return HasMany<MatterCreditorAddress, $this>
     */
    public function addresses(): HasMany
    {
        return $this->hasMany(MatterCreditorAddress::class);
    }
}
