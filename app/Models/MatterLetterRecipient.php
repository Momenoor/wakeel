<?php

namespace App\Models;

use App\Enums\LetterStatus;
use App\Support\Addresses;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable('matter_letter_id', 'recipient_id', 'email', 'emails', 'phones', 'representatives', 'name_representatives', 'name', 'role', 'delivery_status', 'delivered_at', 'failure_reason')]
class MatterLetterRecipient extends Model
{
    public function casts(): array
    {
        return [
            'delivered_at' => 'datetime',
            'emails' => 'array',
            'phones' => 'array',
            'representatives' => 'array',
            'name_representatives' => 'boolean',
            'delivery_status' => LetterStatus::class,
        ];
    }

    /**
     * Whom the letter reaches by email for this recipient, each with their own addresses: the party, then
     * each of its representatives.
     *
     * @return list<array{name: string, emails: list<mixed>}>
     */
    public function addressRows(): array
    {
        return [
            ['name' => (string) $this->name, 'emails' => array_values((array) ($this->emails ?: [$this->email]))],
            ...array_map(fn (array $representative): array => [
                'name' => trim((string) ($representative['name'] ?? '')),
                'emails' => array_values((array) ($representative['emails'] ?? [])),
            ], array_values((array) ($this->representatives ?? []))),
        ];
    }

    /**
     * Their addresses for an email's To, each named after whose it is — the party's under the party's name,
     * the lawyer's under the lawyer's (Addresses::named()).
     *
     * @return list<array{email: string, name: string}>
     */
    public function addressed(): array
    {
        return Addresses::named($this->addressRows());
    }

    /**
     * Everyone the letter reaches by email for this recipient: their own
     * addresses and their representatives'.
     *
     * @return list<string>
     */
    public function allEmails(): array
    {
        return array_column($this->addressed(), 'email');
    }

    public function letter(): BelongsTo
    {
        return $this->belongsTo(MatterLetter::class, 'matter_letter_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'recipient_id');
    }

    public function status(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->delivery_status,
        );
    }
}
