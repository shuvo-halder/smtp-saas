<?php

use App\Http\Controllers\Api\AdminAbuseIncidentApiController;
use App\Http\Controllers\Api\AdminApiController;
use App\Http\Controllers\Api\AdminAuditLogApiController;
use App\Http\Controllers\Api\AdminSmtpApiController;
use App\Http\Controllers\Api\AliasApiController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BillingApiController;
use App\Http\Controllers\Api\DashboardApiController;
use App\Http\Controllers\Api\DomainApiController;
use App\Http\Controllers\Api\MailboxApiController;
use App\Http\Controllers\Api\TenantSmtpApiController;
use App\Http\Middleware\EnsureActiveSubscription;
use App\Http\Middleware\EnsureAdmin;
use Illuminate\Support\Facades\Route;

Route::middleware([\App\Http\Middleware\IdentifyTenant::class])->group(function () {
    // Public Routes
    Route::post('/auth/register', [AuthController::class, 'register']);
    Route::post('/auth/login', [AuthController::class, 'login']);

    // Public Webhooks & Callbacks (Disable CSRF for these in middleware if necessary, though api.php has no CSRF)
    Route::post('/billing/ipn', [BillingApiController::class, 'ipn']);
    Route::post('/billing/success', [BillingApiController::class, 'success']);
    Route::post('/billing/fail', [BillingApiController::class, 'fail']);
    Route::post('/billing/cancel', [BillingApiController::class, 'cancel']);

    // Public Minimal Liveness (Step 19)
    Route::get('/health', [\App\Http\Controllers\Api\HealthApiController::class, 'liveness']);

    // Authenticated Routes
    Route::middleware('auth:sanctum')->group(function () {
        
        // Auth Routes
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/auth/user', [AuthController::class, 'user']);
        Route::post('/webmail/sso', [\App\Http\Controllers\Api\SsoController::class, 'webmailSso']);
        
        // Billing Plans & Subscription (Active Subscription not required)
        Route::get('/billing/subscription', [BillingApiController::class, 'subscription']);
        Route::get('/billing/plans', [BillingApiController::class, 'plans']);
        Route::post('/billing/checkout', [BillingApiController::class, 'checkout']);
        Route::get('/billing/invoices', [BillingApiController::class, 'invoices']);
        Route::get('/billing/invoices/{invoice}', [BillingApiController::class, 'show']);
        Route::get('/billing/invoices/{invoice}/download', [BillingApiController::class, 'downloadInvoice']);

        // Active Subscription Required
        Route::middleware(EnsureActiveSubscription::class)->group(function () {
            Route::get('/dashboard', [DashboardApiController::class, 'index']);
            Route::get('/security/incidents', [DashboardApiController::class, 'securityIncidents']);

            // Domains CRUD, DNS Setup Wizard & Deliverability Reports (Steps 34 & 35)
            Route::apiResource('domains', DomainApiController::class)->except(['update']);
            Route::get('/domains/{domain}/dns-wizard', [DomainApiController::class, 'dnsWizard']);
            Route::get('/domains/{domain}/deliverability-report', [DomainApiController::class, 'deliverabilityReport']);
            Route::post('/domains/{domain}/verify-dns', [DomainApiController::class, 'verifyDns'])->middleware('throttle:30,1');
            Route::post('/domains/{domain}/verify', [DomainApiController::class, 'verify'])->middleware('throttle:30,1');
            Route::post('/domains/{domain}/parse-dmarc-report', [DomainApiController::class, 'parseDmarcReport'])->middleware('throttle:10,1');
            Route::get('/domains/{domain}/catch-all', [\App\Http\Controllers\Api\CatchAllApiController::class, 'show']);
            Route::put('/domains/{domain}/catch-all', [\App\Http\Controllers\Api\CatchAllApiController::class, 'update']);

            // Mailboxes CRUD & Storage Quotas (Step 31)
            Route::get('/mailboxes/storage', [MailboxApiController::class, 'storageOverview']);
            Route::get('/mailboxes/{mailbox}/storage', [MailboxApiController::class, 'mailboxStorage']);
            Route::get('/mailboxes', [MailboxApiController::class, 'allMailboxes']);
            Route::apiResource('domains.mailboxes', MailboxApiController::class)->shallow();
            Route::post('/mailboxes/{mailbox}/change-password', [MailboxApiController::class, 'changePassword']);
            Route::post('/mailboxes/{mailbox}/toggle', [MailboxApiController::class, 'toggle']);

            // Mailbox Forwarding Rules & Auto-Reply (Step 33)
            Route::get('/mailboxes/{mailbox}/rules', [\App\Http\Controllers\Api\MailboxRuleApiController::class, 'show']);
            Route::put('/mailboxes/{mailbox}/forwarding', [\App\Http\Controllers\Api\MailboxRuleApiController::class, 'updateForwarding']);
            Route::put('/mailboxes/{mailbox}/auto-reply', [\App\Http\Controllers\Api\MailboxRuleApiController::class, 'updateAutoReply']);

            // Email Aliases & Forwarding (Step 29)
            Route::get('/aliases', [AliasApiController::class, 'index']);
            Route::post('/aliases', [AliasApiController::class, 'store']);
            Route::get('/aliases/{alias}', [AliasApiController::class, 'show']);
            Route::put('/aliases/{alias}', [AliasApiController::class, 'update']);
            Route::post('/aliases/{alias}/toggle', [AliasApiController::class, 'toggle']);
            Route::delete('/aliases/{alias}', [AliasApiController::class, 'destroy']);
            Route::get('/domains/{domain}/aliases', [AliasApiController::class, 'domainAliases']);

            // Tenant SMTP Management (Step 28)
            Route::prefix('smtp')->group(function () {
                Route::get('/settings', [TenantSmtpApiController::class, 'settings']);
                Route::get('/usage', [TenantSmtpApiController::class, 'usage']);
                Route::get('/credentials', [TenantSmtpApiController::class, 'credentials']);
                Route::post('/credentials/{mailbox}/rotate-password', [TenantSmtpApiController::class, 'rotatePassword']);
                Route::get('/activity', [TenantSmtpApiController::class, 'activity']);
                Route::get('/diagnostics', [TenantSmtpApiController::class, 'diagnostics']);
            });
        });

        // Admin Routes
        Route::middleware(EnsureAdmin::class)->prefix('admin')->group(function () {
            // Stats / Dashboard & System Health (Step 19)
            Route::middleware('admin.permission:admin.stats.read')->group(function () {
                Route::get('/stats', [AdminApiController::class, 'stats']);
                Route::get('/charts', [AdminApiController::class, 'chartData']);
                Route::get('/server-stats', [AdminApiController::class, 'serverStats']);

                // System Health & Scheduled Task Reliability (Step 19)
                Route::prefix('system')->group(function () {
                    Route::get('/health', [\App\Http\Controllers\Api\AdminSystemHealthApiController::class, 'index']);
                    Route::get('/tasks', [\App\Http\Controllers\Api\AdminSystemHealthApiController::class, 'tasks']);
                });
            });
            
            // Users / Tenants
            Route::get('/users', [AdminApiController::class, 'users'])
                ->middleware('admin.permission:admin.users.read');
            Route::get('/users/{user}', [AdminApiController::class, 'showUser'])
                ->middleware('admin.permission:admin.users.read');
            Route::post('/users/{user}/suspend', [AdminApiController::class, 'suspendUser'])
                ->middleware('admin.permission:admin.users.manage');
            Route::post('/users/{user}/activate', [AdminApiController::class, 'activateUser'])
                ->middleware('admin.permission:admin.users.manage');
            
            // Domains & Mailboxes
            Route::get('/domains', [AdminApiController::class, 'domains'])
                ->middleware('admin.permission:admin.domains.read');
            Route::get('/mailboxes', [AdminApiController::class, 'mailboxes'])
                ->middleware('admin.permission:admin.mailboxes.read');
            
            // Plans Management
            Route::get('/plans', [AdminApiController::class, 'plans'])
                ->middleware('admin.permission:admin.plans.read');
            Route::post('/plans', [AdminApiController::class, 'storePlan'])
                ->middleware('admin.permission:admin.plans.manage');
            Route::get('/plans/{plan}', [AdminApiController::class, 'showPlan'])
                ->middleware('admin.permission:admin.plans.read');
            Route::put('/plans/{plan}', [AdminApiController::class, 'updatePlan'])
                ->middleware('admin.permission:admin.plans.manage');
            Route::delete('/plans/{plan}', [AdminApiController::class, 'destroyPlan'])
                ->middleware('admin.permission:admin.plans.manage');
            
            // Invoices
            Route::get('/invoices', [AdminApiController::class, 'invoices'])
                ->middleware('admin.permission:admin.invoices.read');

            // SMTP Management & Deliverability (Step 16A)
            Route::prefix('smtp')->group(function () {
                Route::get('/overview', [AdminSmtpApiController::class, 'overview'])
                    ->middleware('admin.permission:admin.smtp.read');
                Route::get('/tenants', [AdminSmtpApiController::class, 'tenants'])
                    ->middleware('admin.permission:admin.smtp.read');
                Route::get('/tenants/{user}', [AdminSmtpApiController::class, 'showTenant'])
                    ->middleware('admin.permission:admin.smtp.read');
                Route::get('/mailboxes', [AdminSmtpApiController::class, 'mailboxes'])
                    ->middleware('admin.permission:admin.smtp.read');
                Route::get('/abuse', [AdminSmtpApiController::class, 'abuse'])
                    ->middleware('admin.permission:admin.smtp.read');
                Route::post('/mailboxes/{mailbox}/toggle', [AdminSmtpApiController::class, 'toggleMailbox'])
                    ->middleware('admin.permission:admin.smtp.mailbox.toggle');
                Route::post('/mailboxes/{mailbox}/reset-bounces', [AdminSmtpApiController::class, 'resetBounces'])
                    ->middleware('admin.permission:admin.smtp.mailbox.reset_bounces');

                // RBAC-DEC-03: Mailbox password reset requires admin.smtp.mailbox.reset_password
                Route::post('/mailboxes/{mailbox}/reset-password', [AdminSmtpApiController::class, 'resetPassword'])
                    ->middleware('admin.permission:admin.smtp.mailbox.reset_password');

                // Persistent Abuse Incident Ledger (Step 16B.4)
                Route::get('/incidents', [AdminAbuseIncidentApiController::class, 'index'])
                    ->middleware('admin.permission:admin.smtp.read');
                Route::get('/incidents/{incident}', [AdminAbuseIncidentApiController::class, 'show'])
                    ->middleware('admin.permission:admin.smtp.read');
                Route::post('/incidents/{incident}/resolve', [AdminAbuseIncidentApiController::class, 'resolve'])
                    ->middleware('admin.permission:admin.smtp.mailbox.toggle');
            });

            // Persistent Audit Logs (Step 16B.1)
            Route::get('/audit-logs', [AdminAuditLogApiController::class, 'index'])
                ->middleware('admin.permission:admin.audit_logs.read');
            Route::get('/audit-logs/{id}', [AdminAuditLogApiController::class, 'show'])
                ->middleware('admin.permission:admin.audit_logs.read');

            // Tenant Usage, Quota & Billing Reconciliation (Step 24)
            Route::prefix('reconciliation')->group(function () {
                Route::get('/report', [\App\Http\Controllers\Api\AdminReconciliationApiController::class, 'report'])
                    ->middleware('admin.permission:admin.stats.read');
                Route::get('/tenants/{user}', [\App\Http\Controllers\Api\AdminReconciliationApiController::class, 'tenantReport'])
                    ->middleware('admin.permission:admin.stats.read');
            });
        });
    });
});
