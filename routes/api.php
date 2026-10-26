<?php

use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\ProductController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes — v1
|--------------------------------------------------------------------------
|
| Middleware stack for protected routes:
|   auth:sanctum  — verifies token exists and is not expired (expires_at).
|   active        — rejects deactivated accounts and revokes their tokens.
|
| Role access (EnsureRole middleware alias):
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

    // ── Routes shared by both roles ────────────────────────────────────
    // Both admin_gudang and admin_kasir can view categories and products.
    // Write operations are gated by policy inside each controller.
    Route::middleware(['auth:sanctum', 'active'])
        ->group(function () {

            // Categories
            Route::prefix('categories')
                ->name('api.v1.categories.')
                ->group(function () {
                    Route::get('', [CategoryController::class, 'index'])->name('index');
                    Route::post('', [CategoryController::class, 'store'])->name('store');
                    Route::get('{category}', [CategoryController::class, 'show'])->name('show');
                    Route::put('{category}', [CategoryController::class, 'update'])->name('update');
                    Route::delete('{category}', [CategoryController::class, 'destroy'])->name('destroy');
                });

            // Items (products)
            // search MUST be registered before {item} to avoid Laravel treating
            // the literal string "search" as a route model binding value.
            Route::prefix('items')
                ->name('api.v1.items.')
                ->group(function () {
                    Route::get('search', [ProductController::class, 'search'])->name('search');
                    Route::get('', [ProductController::class, 'index'])->name('index');
                    Route::post('', [ProductController::class, 'store'])->name('store');
                    Route::get('{item}', [ProductController::class, 'show'])->name('show');
                    Route::put('{item}', [ProductController::class, 'update'])->name('update');
                    Route::delete('{item}', [ProductController::class, 'destroy'])->name('destroy');
                });
        });

    // ── Warehouse probe (admin_gudang middleware test) ────────────────────
    Route::middleware(['auth:sanctum', 'active', 'role:admin_gudang'])
        ->prefix('warehouse')
        ->name('api.v1.warehouse.')
        ->group(function () {
            Route::get('_probe', fn () => response()->json(['ok' => true]))->name('probe');
        });

    // ── Kasir routes — placeholder for kasir step ────────────────────────
    Route::middleware(['auth:sanctum', 'active', 'role:admin_kasir'])
        ->prefix('kasir')
        ->name('api.v1.kasir.')
        ->group(function () {
            // Product lookup, sales — added in kasir step
        });
});
