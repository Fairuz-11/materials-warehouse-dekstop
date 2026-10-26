<?php

namespace App\Policies;

use App\Models\Product;
use App\Models\User;

/**
 * admin_gudang  — full CRUD + deactivation
 * admin_kasir   — view + search only (product catalogue for POS)
 */
class ProductPolicy
{
    public function viewAny(User $user): bool
    {
        return true; // both roles may list/search
    }

    public function view(User $user, Product $product): bool
    {
        return true; // both roles may view
    }

    public function create(User $user): bool
    {
        return $user->isAdminGudang();
    }

    public function update(User $user, Product $product): bool
    {
        return $user->isAdminGudang();
    }

    /**
     * "Delete" = deactivation. Hard delete is blocked when transaction history
     * exists. admin_kasir may not deactivate products.
     */
    public function delete(User $user, Product $product): bool
    {
        return $user->isAdminGudang();
    }
}
