<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A letter or minutes made again from their template (App\Services\MMS\Letters\DocumentRegenerator): what it was
 * before, to put back.
 */
#[Fillable('document_type', 'document_id', 'letter_template_id', 'user_id', 'previous', 'undone_at')]
class DocumentRegeneration extends Model
{
    protected function casts(): array
    {
        return [
            'previous' => 'array',
            'undone_at' => 'datetime',
        ];
    }

    public function document(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
