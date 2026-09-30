<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\MailSender;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class MailSenderPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:MailSender');
    }

    public function view(AuthUser $authUser, MailSender $mailSender): bool
    {
        return $authUser->can('View:MailSender');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:MailSender');
    }

    public function update(AuthUser $authUser, MailSender $mailSender): bool
    {
        return $authUser->can('Update:MailSender');
    }

    public function delete(AuthUser $authUser, MailSender $mailSender): bool
    {
        return $authUser->can('Delete:MailSender');
    }

    public function restore(AuthUser $authUser, MailSender $mailSender): bool
    {
        return $authUser->can('Restore:MailSender');
    }

    public function forceDelete(AuthUser $authUser, MailSender $mailSender): bool
    {
        return $authUser->can('ForceDelete:MailSender');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:MailSender');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:MailSender');
    }

    public function replicate(AuthUser $authUser, MailSender $mailSender): bool
    {
        return $authUser->can('Replicate:MailSender');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:MailSender');
    }
}
