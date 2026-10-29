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
    protected $fillable = ['matter_id', 'name', 'phones', 'claim_amount'];

    protected function casts(): array
    {
        return [
            'phones' => 'array',
            'claim_amount' => 'decimal:2',
        ];
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
