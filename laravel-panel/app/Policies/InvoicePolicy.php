<?php

namespace App\Policies;

use App\Models\Invoice;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class InvoicePolicy
{
    use HandlesAuthorization;

    public function view(User $user, Invoice $invoice)
    {
        return $user->id === $invoice->user_id || $user->is_admin;
    }
}
