<?php

namespace App\Policies;

use App\Models\User;

/**
 * Authorization rules for User management.
 *
 * Rules:
 * - Only admin_gudang can create, list, view, update, deactivate accounts.
 * - No role can hard-delete users (soft-deactivation only via is_active = false).
 * - Any authenticated user can view and update their own profile via AuthController.
 *   That self-service is handled in the controller without going through this policy.
 *
 * Public registration is intentionally not provided. All accounts are
 * created by an admin_gudang through the account management endpoints
 * (to be built in a later step).
 */
class UserPolicy
{
    /**
     * admin_gudang can list all users.
     */
    public function viewAny(User $user): bool
    {
        return $user->isAdminGudang();
    }

    /**
     * admin_gudang can view any user.
     */
    public function view(User $user, User $model): bool
    {
        return $user->isAdminGudang();
    }

    /**
     * Only admin_gudang can create new accounts.
     * No public registration.
     */
    public function create(User $user): bool
    {
        return $user->isAdminGudang();
    }

    /**
     * admin_gudang can update any account.
     * A user may not update their own role or is_active through this policy
     * (self-service password/profile changes use AuthController directly).
     */
    public function update(User $user, User $model): bool
    {
        return $user->isAdminGudang();
    }

    /**
     * Hard-delete is not permitted. Use deactivation (is_active = false).
     */
    public function delete(User $user, User $model): bool
    {
        return false;
    }

    /**
     * Not applicable — no soft-deletes on users.
     */
    public function restore(User $user, User $model): bool
    {
        return false;
    }

    /**
     * Hard-delete is never allowed.
     */
    public function forceDelete(User $user, User $model): bool
    {
        return false;
    }
}
