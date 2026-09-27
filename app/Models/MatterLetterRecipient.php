<?php

namespace App\Models;

use App\Enums\LetterStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable('matter_letter_id', 'recipient_id', 'email', 'emails', 'name', 'role', 'delivery_status', 'delivered_at')]
class MatterLetterRecipient extends Model
{
    public function casts(): array
    {
        return [
            'delivered_at' => 'datetime',
            'emails' => 'array',
            'delivery_status' => LetterStatus::class,
        ];
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
