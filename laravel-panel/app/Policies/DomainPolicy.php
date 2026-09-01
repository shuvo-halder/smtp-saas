<?php

namespace App\Policies;

use App\Models\Domain;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class DomainPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user)
    {
        return true;
    }

    public function view(User $user, Domain $domain)
    {
        return $user->id === $domain->user_id;
    }

    public function create(User $user)
    {
        return true;
    }

    public function update(User $user, Domain $domain)
    {
        return $user->id === $domain->user_id;
    }

    public function delete(User $user, Domain $domain)
    {
        return $user->id === $domain->user_id;
    }
}
