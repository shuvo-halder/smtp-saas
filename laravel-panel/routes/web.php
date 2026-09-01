<?php

use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\DomainController;
use App\Http\Controllers\MailboxController;
use Illuminate\Support\Facades\Route;

// ─── Public Routes ─────────────────────────────────────────────────────────────

Route::get('/', fn() => view('welcome'))->name('home');

// ─── Authentication (Laravel Breeze / Fortify) ─────────────────────────────────
require __DIR__.'/auth.php';

// ─── Billing IPN (no auth - called by SSLCommerz) ─────────────────────────────
Route::post('/billing/ipn', [BillingController::class, 'ipn'])->name('billing.ipn');

// ─── Authenticated Customer Routes ─────────────────────────────────────────────
Route::middleware(['auth', 'verified', 'active.subscription'])->group(function () {

    // Dashboard
    Route::get('/dashboard', function () {
        $user    = auth()->user()->load('plan');
        $domains = $user->domains()->withCount('mailboxes')->latest()->take(5)->get();
        $invoices = $user->invoices()->take(5)->get();
        return view('dashboard.index', compact('user', 'domains', 'invoices'));
    })->name('dashboard');

    // ─── Domains ───────────────────────────────────────────────────────────────
    Route::prefix('domains')->name('domains.')->group(function () {
        Route::get('/',              [DomainController::class, 'index'])->name('index');
        Route::get('/create',        [DomainController::class, 'create'])->name('create');
        Route::post('/',             [DomainController::class, 'store'])->name('store');
        Route::get('/{domain}',      [DomainController::class, 'show'])->name('show');
        Route::get('/{domain}/setup',[DomainController::class, 'setup'])->name('setup');
        Route::post('/{domain}/verify', [DomainController::class, 'verify'])->name('verify');
        Route::delete('/{domain}',   [DomainController::class, 'destroy'])->name('destroy');
    });

    // ─── Mailboxes ─────────────────────────────────────────────────────────────
    Route::prefix('domains/{domain}/mailboxes')->name('mailboxes.')->group(function () {
        Route::get('/',                          [MailboxController::class, 'index'])->name('index');
        Route::get('/create',                    [MailboxController::class, 'create'])->name('create');
        Route::post('/',                         [MailboxController::class, 'store'])->name('store');
        Route::post('/{mailbox}/password',       [MailboxController::class, 'changePassword'])->name('password');
        Route::post('/{mailbox}/toggle',         [MailboxController::class, 'toggle'])->name('toggle');
        Route::delete('/{mailbox}',              [MailboxController::class, 'destroy'])->name('destroy');
    });

    // ─── Billing ───────────────────────────────────────────────────────────────
    Route::prefix('billing')->name('billing.')->group(function () {
        Route::get('/',              [BillingController::class, 'index'])->name('index');
        Route::get('/plans',         [BillingController::class, 'plans'])->name('plans');
        Route::post('/checkout',     [BillingController::class, 'checkout'])->name('checkout');
        Route::get('/success',       [BillingController::class, 'success'])->name('success');
        Route::get('/fail',          [BillingController::class, 'fail'])->name('fail');
        Route::get('/cancel',        [BillingController::class, 'cancel'])->name('cancel');
        Route::get('/invoice/{invoice}/download', [BillingController::class, 'downloadInvoice'])->name('invoice.download');
    });
});

// Allow billing page without active subscription
Route::middleware('auth')->group(function () {
    Route::get('/billing/plans', [BillingController::class, 'plans'])->name('billing.plans.guest');
    Route::post('/billing/checkout', [BillingController::class, 'checkout'])->name('billing.checkout.guest');
});

// ─── Admin Routes ──────────────────────────────────────────────────────────────
Route::middleware(['auth', 'admin'])->prefix('admin')->name('admin.')->group(function () {

    Route::get('/',            [AdminDashboardController::class, 'index'])->name('dashboard');
    Route::get('/server-stats',[AdminDashboardController::class, 'serverStats'])->name('server.stats');

    // Users
    Route::get('/users',              [AdminDashboardController::class, 'users'])->name('users.index');
    Route::get('/users/{user}',       [AdminDashboardController::class, 'showUser'])->name('users.show');
    Route::post('/users/{user}/suspend', [AdminDashboardController::class, 'suspendUser'])->name('users.suspend');
    Route::post('/users/{user}/activate',[AdminDashboardController::class, 'activateUser'])->name('users.activate');

    // Plans
    Route::get('/plans',              [AdminDashboardController::class, 'plans'])->name('plans.index');
    Route::get('/plans/create',       [AdminDashboardController::class, 'createPlan'])->name('plans.create');
    Route::post('/plans',             [AdminDashboardController::class, 'storePlan'])->name('plans.store');

    // Invoices
    Route::get('/invoices',           [AdminDashboardController::class, 'invoices'])->name('invoices.index');
});
