<?php

namespace App\Policies;

use App\Models\Category;
use App\Models\User;

/**
 * admin_gudang  — full CRUD + deactivation
 * admin_kasir   — view only (active categories for point-of-sale reference)
 */
class CategoryPolicy
{
    public function viewAny(User $user): bool
    {
        return true; // both roles may list
    }

    public function view(User $user, Category $category): bool
    {
        return true; // both roles may view
    }

    public function create(User $user): bool
    {
        return $user->isAdminGudang();
    }

    public function update(User $user, Category $category): bool
    {
        return $user->isAdminGudang();
    }

    /**
     * "Delete" means deactivation (is_active = false), never hard-delete.
     * Hard delete is blocked in the controller when the category has products.
     */
    public function delete(User $user, Category $category): bool
    {
        return $user->isAdminGudang();
    }
}
