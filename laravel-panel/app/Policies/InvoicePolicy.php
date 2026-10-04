<?php

namespace App\Policies;

use App\Enums\PermissionEnum;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class InvoicePolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view or download the invoice.
     *
     * Rules:
     * 1. Tenant ownership: customers are authorized to access their own invoices.
     * 2. Administrative access: suspended accounts cannot exercise administrative access.
     * 3. Granular RBAC: cross-tenant access requires explicit admin.invoices.read permission on web guard.
     */
    public function view(User $user, Invoice $invoice): bool
    {
        // 1. Tenant ownership: customers always have access to their own invoices
        if ($user->id === $invoice->user_id) {
            return true;
        }

        // 2. Suspended accounts cannot exercise administrative access
        if ($user->status === 'suspended') {
            return false;
        }

        // 3. Administrative cross-tenant access requires explicit RBAC permission on web guard
        return $user->hasPermissionTo(PermissionEnum::ADMIN_INVOICES_READ->value, 'web');
    }
}
