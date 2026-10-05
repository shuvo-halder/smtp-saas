<?php

use App\Http\Controllers\Api\AdminAbuseIncidentApiController;
use App\Http\Controllers\Api\AdminApiController;
use App\Http\Controllers\Api\AdminAuditLogApiController;
use App\Http\Controllers\Api\AdminSmtpApiController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BillingApiController;
use App\Http\Controllers\Api\DashboardApiController;
use App\Http\Controllers\Api\DomainApiController;
use App\Http\Controllers\Api\MailboxApiController;
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

    // Authenticated Routes
    Route::middleware('auth:sanctum')->group(function () {
        
        // Auth Routes
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/auth/user', [AuthController::class, 'user']);
        Route::post('/webmail/sso', [\App\Http\Controllers\Api\SsoController::class, 'webmailSso']);
        
        // Billing Plans (Active Subscription not required)
        Route::get('/billing/plans', [BillingApiController::class, 'plans']);
        Route::post('/billing/checkout', [BillingApiController::class, 'checkout']);
        Route::get('/billing/invoices', [BillingApiController::class, 'invoices']);
        Route::get('/billing/invoices/{invoice}/download', [BillingApiController::class, 'downloadInvoice']);

        // Active Subscription Required
        Route::middleware(EnsureActiveSubscription::class)->group(function () {
            Route::get('/dashboard', [DashboardApiController::class, 'index']);

            // Domains CRUD
            Route::apiResource('domains', DomainApiController::class)->except(['update']);
            Route::post('/domains/{domain}/verify', [DomainApiController::class, 'verify']);

            // Mailboxes CRUD
            Route::apiResource('domains.mailboxes', MailboxApiController::class)->shallow()->except(['show', 'update']);
            Route::post('/mailboxes/{mailbox}/change-password', [MailboxApiController::class, 'changePassword']);
            Route::post('/mailboxes/{mailbox}/toggle', [MailboxApiController::class, 'toggle']);
        });

        // Admin Routes
        Route::middleware(EnsureAdmin::class)->prefix('admin')->group(function () {
            // Stats / Dashboard
            Route::middleware('admin.permission:admin.stats.read')->group(function () {
                Route::get('/stats', [AdminApiController::class, 'stats']);
                Route::get('/charts', [AdminApiController::class, 'chartData']);
                Route::get('/server-stats', [AdminApiController::class, 'serverStats']);
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
        });
    });
});
