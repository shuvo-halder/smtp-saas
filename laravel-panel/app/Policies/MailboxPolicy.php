<?php

namespace App\Policies;

use App\Models\Mailbox;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class MailboxPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Mailbox $mailbox): bool
    {
        return $user->id === $mailbox->domain?->user_id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Mailbox $mailbox): bool
    {
        return $user->id === $mailbox->domain?->user_id;
    }

    public function delete(User $user, Mailbox $mailbox): bool
    {
        return $user->id === $mailbox->domain?->user_id;
    }
}
