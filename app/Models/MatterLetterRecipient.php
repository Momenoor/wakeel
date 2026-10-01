<?php

namespace App\Models;

use App\Enums\LetterStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable('matter_letter_id', 'recipient_id', 'email', 'emails', 'representatives', 'name_representatives', 'name', 'role', 'delivery_status', 'delivered_at', 'failure_reason')]
class MatterLetterRecipient extends Model
{
    public function casts(): array
    {
        return [
            'delivered_at' => 'datetime',
            'emails' => 'array',
            'representatives' => 'array',
            'name_representatives' => 'boolean',
            'delivery_status' => LetterStatus::class,
        ];
    }

    /**
     * Everyone the letter reaches by email for this recipient: their own
     * addresses and their representatives'.
     *
     * @return list<string>
     */
    public function allEmails(): array
    {
        $emails = [
            ...($this->emails ?: [$this->email]),
            ...collect($this->representatives ?? [])->flatMap(fn (array $representative): array => (array) ($representative['emails'] ?? []))->all(),
        ];

        return array_values(array_unique(array_filter($emails, fn ($email): bool => filter_var($email, FILTER_VALIDATE_EMAIL) !== false)));
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
