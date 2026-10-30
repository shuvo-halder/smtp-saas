<?php

namespace App\Policies;

use App\Models\MailDeliveryRecord;
use App\Models\User;

class MailDeliveryPolicy
{
    /**
     * Determine whether the user can view any delivery records.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the specific delivery record.
     */
    public function view(User $user, MailDeliveryRecord $record): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $user->id === $record->tenant_id;
    }

    /**
     * Determine whether the user can retry the delivery record.
     */
    public function retry(User $user, MailDeliveryRecord $record): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $user->id === $record->tenant_id;
    }

    /**
     * Determine whether the user can cancel the delivery record.
     */
    public function cancel(User $user, MailDeliveryRecord $record): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $user->id === $record->tenant_id;
    }
}
