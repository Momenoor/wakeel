<?php

namespace App\Models\Contracts;

use App\Enums\AttachmentCategory;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * A record files can be attached to (a fee, an allocation, a request, a matter …): it says which matter the
 * files belong to as their context, and which categories it may hold. Who may see or change its files is
 * decided by its matter's policy (see AttachmentPolicy).
 */
interface AttachmentOwner
{
    /** The files this record owns. */
    public function ownedAttachments(): MorphMany;

    /** The matter its files belong to as context — null for a record with none (a party, an employee). */
    public function attachmentMatterId(): ?int;

    /**
     * @return list<AttachmentCategory>
     */
    public function attachmentCategories(): array;
}
