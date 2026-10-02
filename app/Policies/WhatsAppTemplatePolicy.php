<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\WhatsAppTemplate;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class WhatsAppTemplatePolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:WhatsAppTemplate');
    }

    public function view(AuthUser $authUser, WhatsAppTemplate $whatsAppTemplate): bool
    {
        return $authUser->can('View:WhatsAppTemplate');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:WhatsAppTemplate');
    }

    public function update(AuthUser $authUser, WhatsAppTemplate $whatsAppTemplate): bool
    {
        return $authUser->can('Update:WhatsAppTemplate');
    }

    public function delete(AuthUser $authUser, WhatsAppTemplate $whatsAppTemplate): bool
    {
        return $authUser->can('Delete:WhatsAppTemplate');
    }

    public function restore(AuthUser $authUser, WhatsAppTemplate $whatsAppTemplate): bool
    {
        return $authUser->can('Restore:WhatsAppTemplate');
    }

    public function forceDelete(AuthUser $authUser, WhatsAppTemplate $whatsAppTemplate): bool
    {
        return $authUser->can('ForceDelete:WhatsAppTemplate');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:WhatsAppTemplate');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:WhatsAppTemplate');
    }

    public function replicate(AuthUser $authUser, WhatsAppTemplate $whatsAppTemplate): bool
    {
        return $authUser->can('Replicate:WhatsAppTemplate');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:WhatsAppTemplate');
    }
}
