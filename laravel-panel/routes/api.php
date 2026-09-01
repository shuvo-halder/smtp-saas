<?php

use App\Http\Controllers\Api\AdminApiController;
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

    // Public Webhook
    Route::post('/billing/ipn', [BillingApiController::class, 'ipn']);

    // Authenticated Routes
    Route::middleware('auth:sanctum')->group(function () {
        
        // Auth Routes
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/auth/user', [AuthController::class, 'user']);
        
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
            Route::get('/stats', [AdminApiController::class, 'stats']);
            
            Route::get('/users', [AdminApiController::class, 'users']);
            Route::post('/users/{user}/suspend', [AdminApiController::class, 'suspendUser']);
            Route::post('/users/{user}/activate', [AdminApiController::class, 'activateUser']);
            
            Route::get('/plans', [AdminApiController::class, 'plans']);
            Route::post('/plans', [AdminApiController::class, 'storePlan']);
            
            Route::get('/invoices', [AdminApiController::class, 'invoices']);
            
            Route::get('/server-stats', [AdminApiController::class, 'serverStats']);
        });
    });
});
