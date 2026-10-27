<?php

namespace App\Policies;

use App\Models\TenantNotification;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class TenantNotificationPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, TenantNotification $notification): bool
    {
        return $user->id === $notification->tenant_id;
    }

    public function update(User $user, TenantNotification $notification): bool
    {
        return $user->id === $notification->tenant_id;
    }

    public function delete(User $user, TenantNotification $notification): bool
    {
        return $user->id === $notification->tenant_id;
    }
}
