<?php

namespace App\Enums;

enum PermissionEnum: string
{
    // Admin Dashboard & System Telemetry
    case ADMIN_STATS_READ = 'admin.stats.read';

    // User & Tenant Governance
    case ADMIN_USERS_READ = 'admin.users.read';
    case ADMIN_USERS_MANAGE = 'admin.users.manage';

    // Domain Observability
    case ADMIN_DOMAINS_READ = 'admin.domains.read';

    // Mailbox Observability
    case ADMIN_MAILBOXES_READ = 'admin.mailboxes.read';

    // Plan Management
    case ADMIN_PLANS_READ = 'admin.plans.read';
    case ADMIN_PLANS_MANAGE = 'admin.plans.manage';

    // Billing & Invoice Observability
    case ADMIN_INVOICES_READ = 'admin.invoices.read';

    // SMTP Observability & Deliverability Controls
    case ADMIN_SMTP_READ = 'admin.smtp.read';
    case ADMIN_SMTP_MAILBOX_TOGGLE = 'admin.smtp.mailbox.toggle';
    case ADMIN_SMTP_MAILBOX_RESET_BOUNCES = 'admin.smtp.mailbox.reset_bounces';
    case ADMIN_SMTP_MAILBOX_RESET_PASSWORD = 'admin.smtp.mailbox.reset_password';

    // Administrative Audit Ledger
    case ADMIN_AUDIT_LOGS_READ = 'admin.audit_logs.read';

    // RBAC & Role Governance
    case ADMIN_ROLES_MANAGE = 'admin.roles.manage';

    /**
     * Get all permission string values.
     *
     * @return array<string>
     */
    public static function all(): array
    {
        return array_column(self::cases(), 'value');
    }
}
