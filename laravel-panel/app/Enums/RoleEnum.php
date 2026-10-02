<?php

namespace App\Enums;

enum RoleEnum: string
{
    case SUPER_ADMIN = 'super_admin';
    case DELIVERABILITY_OPERATOR = 'deliverability_operator';
    case CUSTOMER_SUPPORT = 'customer_support';

    /**
     * Get all role values.
     *
     * @return array<string>
     */
    public static function all(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Get administrative role values.
     *
     * @return array<string>
     */
    public static function adminRoles(): array
    {
        return [
            self::SUPER_ADMIN->value,
            self::DELIVERABILITY_OPERATOR->value,
            self::CUSTOMER_SUPPORT->value,
        ];
    }

    /**
     * Map of permissions for each role.
     *
     * @return array<string>
     */
    public function permissions(): array
    {
        return match ($this) {
            // Super Admin: Explicit permissions per RBAC-DEC-02 (All defined permissions)
            self::SUPER_ADMIN => PermissionEnum::all(),

            // Deliverability Operator: SMTP, deliverability, and mailbox password reset per RBAC-DEC-03
            self::DELIVERABILITY_OPERATOR => [
                PermissionEnum::ADMIN_STATS_READ->value,
                PermissionEnum::ADMIN_DOMAINS_READ->value,
                PermissionEnum::ADMIN_MAILBOXES_READ->value,
                PermissionEnum::ADMIN_SMTP_READ->value,
                PermissionEnum::ADMIN_SMTP_MAILBOX_TOGGLE->value,
                PermissionEnum::ADMIN_SMTP_MAILBOX_RESET_BOUNCES->value,
                PermissionEnum::ADMIN_SMTP_MAILBOX_RESET_PASSWORD->value,
                PermissionEnum::ADMIN_AUDIT_LOGS_READ->value,
            ],

            // Customer Support: Read-only access to customer resources, plans, invoices, stats
            self::CUSTOMER_SUPPORT => [
                PermissionEnum::ADMIN_STATS_READ->value,
                PermissionEnum::ADMIN_USERS_READ->value,
                PermissionEnum::ADMIN_DOMAINS_READ->value,
                PermissionEnum::ADMIN_MAILBOXES_READ->value,
                PermissionEnum::ADMIN_PLANS_READ->value,
                PermissionEnum::ADMIN_INVOICES_READ->value,
                PermissionEnum::ADMIN_SMTP_READ->value,
                PermissionEnum::ADMIN_AUDIT_LOGS_READ->value,
            ],
        };
    }
}
