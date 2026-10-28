<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Make physical_stock nullable in stock_opname_items.
     *
     * DESIGN DECISION:
     *   - physical_stock = NULL  → item has not been physically counted yet (draft only)
     *   - physical_stock = 0     → counted, result is zero (valid, not "empty")
     *   - Confirmation must be blocked when any item has physical_stock IS NULL
     *
     * GENERATED COLUMN BEHAVIOUR:
     *   difference is GENERATED ALWAYS AS (physical_stock - system_stock) STORED.
     *   MySQL automatically allows NULL in the generated column when the base
     *   column is nullable, so no change to the difference definition is needed
     *   in up().  When difference is NULL it means "not yet counted".
     *
     * APPROACH (no doctrine/dbal required):
     *   Laravel 13 + MySQL 8 support native MODIFY COLUMN via DB::statement.
     *   We avoid Schema::table()->change() for physical_stock because that column
     *   is referenced by a STORED GENERATED column; some older versions of the
     *   Blueprint change() path regenerate the full table definition and can drop
     *   generated columns.  A targeted MODIFY COLUMN is safer.
     *
     * NOTE: This migration was already run with an empty body (batch 4), so the
     *   database already has physical_stock as nullable.  The up() method is
     *   idempotent — re-running it on an already-nullable column is a no-op in
     *   MySQL (MODIFY COLUMN to the same definition succeeds silently).
     */
    public function up(): void
    {
        // Ensure physical_stock is nullable.
        // MODIFY COLUMN on a column that already matches the target definition
        // is a no-op in MySQL 8, so this is safe to run multiple times.
        DB::statement('
            ALTER TABLE stock_opname_items
            MODIFY COLUMN physical_stock DECIMAL(15,3) NULL
        ');
    }

    /**
     * Reverse: restore physical_stock to NOT NULL.
     *
     * Safety guard: if any row has physical_stock = NULL we refuse to roll back
     * silently.  Replacing NULLs with 0 would corrupt the audit trail (0 is a
     * valid physical count result and must not be fabricated).  Instead we throw
     * a descriptive exception so the operator is aware before any data is changed.
     *
     * @throws RuntimeException when NULL rows exist and rollback would lose data
     */
    public function down(): void
    {
        $nullCount = DB::table('stock_opname_items')
            ->whereNull('physical_stock')
            ->count();

        if ($nullCount > 0) {
            throw new RuntimeException(
                "Cannot roll back migration: {$nullCount} row(s) in stock_opname_items "
                .'have physical_stock = NULL. Replacing NULLs with 0 would fabricate '
                .'audit data. Resolve the NULL values manually before rolling back.'
            );
        }

        // Restore NOT NULL. The GENERATED difference column expression stays
        // unchanged because it handles NULL → NULL naturally, and once
        // physical_stock is NOT NULL, difference is also NOT NULL.
        DB::statement('
            ALTER TABLE stock_opname_items
            MODIFY COLUMN physical_stock DECIMAL(15,3) NOT NULL
        ');
    }
};
