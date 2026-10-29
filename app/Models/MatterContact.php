<?php

namespace App\Models;

use App\Services\MMS\SenderMailer;
use App\Support\Addresses;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

/**
 * An address someone brought into a matter: emailed on it, they replied
 * with others in To or CC — those are kept as theirs, on this matter only
 * (not on their party). When they are emailed on the matter again, these
 * are copied in on the email to them; the contacts export lists them.
 */
class MatterContact extends Model
{
    /** Mail providers: a mailbox there doesn't make everyone there the office. */
    public const SHARED_DOMAINS = ['@gmail.com', '@hotmail.com', '@outlook.com', '@live.com', '@yahoo.com', '@icloud.com'];

    protected $fillable = ['matter_id', 'party_id', 'owner_email', 'owner_name', 'email', 'matter_email_id'];

    /**
     * The addresses a received email of a matter was also sent to, kept as
     * its sender's — never the office's own, nor anyone already a party.
     * How many were new.
     */
    public static function learn(MatterEmail $email): int
    {
        [$ownerName, $owner] = self::split((string) $email->from);

        if (! $email->matter_id || $owner === '') {
            return 0;
        }

        $others = array_filter(
            Addresses::emails([...(array) $email->to, ...(array) $email->cc]),
            fn (string $address): bool => mb_strtolower($address) !== $owner && ! self::isOurs($address) && ! self::isParty($address),
        );

        $partyId = self::partyOf((int) $email->matter_id, $owner);
        $added = 0;

        foreach ($others as $address) {
            $contact = static::firstOrCreate(
                ['matter_id' => $email->matter_id, 'owner_email' => $owner, 'email' => mb_strtolower($address)],
                ['party_id' => $partyId, 'owner_name' => $ownerName ?: null, 'matter_email_id' => $email->getKey()],
            );
            $added += $contact->wasRecentlyCreated ? 1 : 0;
        }

        return $added;
    }

    /**
     * The matter's contacts of whoever these addresses (or parties) are —
     * as kept, for an email to them.
     *
     * @param  list<string>  $to
     * @param  list<int>  $partyIds
     * @return Collection<int, self>
     */
    public static function of(?int $matterId, array $to, array $partyIds = []): Collection
    {
        $to = array_map('mb_strtolower', Addresses::emails($to));
        $partyIds = array_values(array_filter(array_map('intval', $partyIds)));

        if (! $matterId || ($to === [] && $partyIds === [])) {
            return collect();
        }

        return static::query()
            ->where('matter_id', $matterId)
            ->where(fn ($q) => $q->whereIn('owner_email', $to)->orWhereIn('party_id', $partyIds ?: [0]))
            ->orderBy('id')
            ->get();
    }

    /**
     * Those of an email's recipients to copy in on it — of the ones the
     * sender left ticked ($allowed; null: all).
     *
     * @param  list<string>  $to
     * @param  list<int>  $partyIds
     * @param  list<string>|null  $allowed
     * @return list<string>
     */
    public static function copiedFor(?int $matterId, array $to, array $partyIds = [], ?array $allowed = null): array
    {
        $allowed = $allowed === null ? null : array_map('mb_strtolower', $allowed);

        return Addresses::without(
            Addresses::emails(self::of($matterId, $to, $partyIds)
                ->pluck('email')
                ->filter(fn (string $email): bool => $allowed === null || in_array($email, $allowed, true))
                ->all()),
            $to,
        );
    }

    /**
     * For a send's form: each address, and whose it is.
     *
     * @param  list<string>  $to
     * @param  list<int>  $partyIds
     * @return array<string, string>
     */
    public static function options(?int $matterId, array $to, array $partyIds = []): array
    {
        return self::of($matterId, $to, $partyIds)
            ->unique('email')
            ->mapWithKeys(fn (self $c): array => [$c->email => __(':email — from :name\'s reply', ['email' => $c->email, 'name' => $c->owner_name ?: $c->owner_email])])
            ->all();
    }

    /**
     * Someone's on the matter, as a person wrote them: those not listed
     * removed, the new added — theirs by their own first address (and
     * party, when they are one).
     *
     * @param  list<string>  $own  their own addresses
     * @param  list<string>  $emails
     */
    public static function setFor(int $matterId, ?int $partyId, array $own, array $emails): void
    {
        $emails = array_map('mb_strtolower', Addresses::without(Addresses::emails($emails), $own));
        $owner = mb_strtolower(Addresses::emails($own)[0] ?? '');

        self::of($matterId, $own, $partyId ? [$partyId] : [])
            ->reject(fn (self $c): bool => in_array($c->email, $emails, true))
            ->each->delete();

        $have = self::of($matterId, $own, $partyId ? [$partyId] : [])->pluck('email')->all();

        foreach (array_diff($emails, $have) as $email) {
            if ($owner !== '') {
                static::firstOrCreate(['matter_id' => $matterId, 'owner_email' => $owner, 'email' => $email], ['party_id' => $partyId]);
            }
        }
    }

    /** "Name <a@b>" as [name, a@b] (lower case). */
    public static function split(string $from): array
    {
        if (preg_match('/^(.*)<([^>]+)>\s*$/u', $from, $m)) {
            return [trim($m[1], " \"'"), mb_strtolower(trim($m[2]))];
        }

        return ['', mb_strtolower(trim($from))];
    }

    /** The office's: one of its mailboxes, its domain, a user. */
    public static function isOurs(string $address): bool
    {
        $ours = once(fn (): array => collect(SenderMailer::all())->pluck('address')->filter()->map(fn ($a) => mb_strtolower((string) $a))->values()->all());

        $address = mb_strtolower($address);
        // The office's own domain — not a mail provider everyone shares.
        $domains = array_diff(array_map(fn (string $a): string => substr($a, (int) strrpos($a, '@')), $ours), self::SHARED_DOMAINS);

        return in_array($address, $ours, true)
            || in_array(substr($address, (int) strrpos($address, '@')), $domains, true)
            || User::query()->whereRaw('lower(email) = ?', [$address])->exists();
    }

    private static function isParty(string $address): bool
    {
        return self::partiesWith($address)->isNotEmpty();
    }

    /** The party whose address this is — one on the matter first. */
    private static function partyOf(int $matterId, string $address): ?int
    {
        $parties = self::partiesWith($address);
        if ($parties->isEmpty()) {
            return null;
        }

        $onMatter = MatterParty::query()->where('matter_id', $matterId)->whereIn('party_id', $parties)->value('party_id');

        return (int) ($onMatter ?? $parties->first());
    }

    /**
     * @return Collection<int, int>
     */
    public static function partiesWith(string $address): Collection
    {
        $address = mb_strtolower(trim($address));

        // Narrowed by the database, then exactly: a "_" in an address is a wildcard there.
        return Party::query()->where('email', 'like', '%'.$address.'%')->get(['id', 'email'])
            ->filter(fn (Party $party): bool => in_array($address, array_map('mb_strtolower', Party::values($party->email)), true))
            ->pluck('id')
            ->values();
    }

    /**
     * @return BelongsTo<Matter, $this>
     */
    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    /**
     * @return BelongsTo<Party, $this>
     */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }
}
