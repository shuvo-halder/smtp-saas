<?php

// Legacy web controllers removed
use Illuminate\Support\Facades\Route;

// ─── Public Routes ─────────────────────────────────────────────────────────────

Route::get('/', fn() => response()->json(['status' => 'API is running']))->name('home');

// ─── Authentication (Laravel Breeze / Fortify) ─────────────────────────────────
// require __DIR__.'/auth.php';

// ─── Billing Webhooks & Callbacks (SSLCommerz) ──────────────────────────────
// Retained in web.php because SSLCommerz gateway relies on these specific endpoints 
// and Route::name() generation without the /api prefix or middleware interference.
Route::post('/billing/ipn', [App\Http\Controllers\Api\BillingApiController::class, 'ipn'])->name('billing.ipn');
Route::post('/billing/success', [App\Http\Controllers\Api\BillingApiController::class, 'success'])->name('billing.success');
Route::post('/billing/fail', [App\Http\Controllers\Api\BillingApiController::class, 'fail'])->name('billing.fail');
Route::post('/billing/cancel', [App\Http\Controllers\Api\BillingApiController::class, 'cancel'])->name('billing.cancel');

// ─── Admin Routes ──────────────────────────────────────────────────────────────
// Admin routes have been migrated to Next.js API in routes/api.php
