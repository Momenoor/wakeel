<?php

namespace App\Models;

use App\Enums\AttachmentCategory;
use App\Models\Concerns\HasAttachments;
use App\Models\Contracts\AttachmentOwner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * One of the office's companies (Human Resources → Companies): its trade licence and establishment card, their
 * expiry watched (ExpiringDocuments), their copies kept as its documents.
 */
class Company extends Model implements AttachmentOwner
{
    use HasAttachments;
    use LogsActivity;
    use SoftDeletes;

    /** The documents whose expiry is watched (ExpiringDocuments): column => label. */
    public const EXPIRING = [
        'trade_license_expiry' => 'Trade licence',
        'establishment_card_expiry' => 'Establishment card',
    ];

    protected $fillable = [
        'name',
        'name_ar',
        'trade_license_no',
        'license_authority',
        'trade_license_start',
        'trade_license_expiry',
        'establishment_card_no',
        'establishment_card_issue',
        'establishment_card_expiry',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'trade_license_start' => 'date',
            'trade_license_expiry' => 'date',
            'establishment_card_issue' => 'date',
            'establishment_card_expiry' => 'date',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll();
    }

    /** Its name in the user's language. */
    public function displayName(): string
    {
        return app()->getLocale() === 'ar' && filled($this->name_ar) ? (string) $this->name_ar : (string) $this->name;
    }

    public function attachmentMatterId(): ?int
    {
        return null;
    }

    public function attachmentCategories(): array
    {
        return [AttachmentCategory::TRADE_LICENSE, AttachmentCategory::ESTABLISHMENT_CARD, AttachmentCategory::OTHER];
    }

    /** Its documents are seen by whoever may see it, and added or removed by whoever may change it. */
    public function allowsAttachment(User $user, string $ability): bool
    {
        return $user->can($ability === 'view' ? 'view' : 'update', $this);
    }
}
