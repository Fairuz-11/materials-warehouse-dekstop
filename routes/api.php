<?php

use App\Http\Controllers\Api\V1\Auth\AuthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes — v1
|--------------------------------------------------------------------------
|
| Middleware stack for protected routes:
|   auth:sanctum  — verifies token exists in personal_access_tokens and is
|                   not expired (checks expires_at column).
|   active        — defense-in-depth: rejects deactivated accounts and
|                   revokes any remaining tokens they hold.
|
| Token expiration: each token is issued with expires_at = now + 8 hours.
| Sanctum checks expires_at on every request via the auth:sanctum guard.
| Expired tokens are rejected with 401 Unauthenticated before hitting any
| controller. The sanctum:prune-expired command can clean them up on a
| schedule.
|
| Role access rules (enforced via 'role' middleware alias → EnsureRole):
|   admin_gudang — full warehouse management + user account management
|   admin_kasir  — product browsing + kasir features (future step)
|
*/

Route::prefix('v1')->group(function () {

    // ── Auth (public) ────────────────────────────────────────────────────
    Route::prefix('auth')->name('api.v1.auth.')->group(function () {
        Route::post('login', [AuthController::class, 'login'])->name('login');
    });

    // ── Auth (protected) ─────────────────────────────────────────────────
    Route::prefix('auth')
        ->name('api.v1.auth.')
        ->middleware(['auth:sanctum', 'active'])
        ->group(function () {
            Route::post('logout', [AuthController::class, 'logout'])->name('logout');
            Route::get('me', [AuthController::class, 'me'])->name('me');
            Route::put('password', [AuthController::class, 'updatePassword'])->name('password');
        });

    // ── Warehouse routes (admin_gudang only) — placeholder for next steps ─
    Route::middleware(['auth:sanctum', 'active', 'role:admin_gudang'])
        ->prefix('warehouse')
        ->name('api.v1.warehouse.')
        ->group(function () {
            // Categories, products, stock transactions — added in later steps

            // Probe route: used by tests to verify middleware stack fires correctly.
            // Returns 200 when all middleware passes (auth + active + role:admin_gudang).
            Route::get('_probe', fn () => response()->json(['ok' => true]))->name('probe');
        });

    // ── Kasir routes (admin_kasir only) — placeholder for future step ─────
    Route::middleware(['auth:sanctum', 'active', 'role:admin_kasir'])
        ->prefix('kasir')
        ->name('api.v1.kasir.')
        ->group(function () {
            // Product lookup, sales — added in kasir step
        });
});
