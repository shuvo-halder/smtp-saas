<?php

namespace App\Policies;

use App\Models\EmailAlias;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class EmailAliasPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, EmailAlias $alias): bool
    {
        return $user->id === $alias->domain?->user_id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, EmailAlias $alias): bool
    {
        return $user->id === $alias->domain?->user_id;
    }

    public function delete(User $user, EmailAlias $alias): bool
    {
        return $user->id === $alias->domain?->user_id;
    }
}
