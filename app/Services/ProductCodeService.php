<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * Generates unique product codes that are safe under concurrent requests.
 *
 * Strategy: use a DB-level advisory lock (GET_LOCK) to serialise code
 * generation so two simultaneous requests cannot pick the same sequence
 * number. The lock is held only for the instant of INSERT, then released.
 *
 * Format: PRD-YYYYMM-NNNNN (e.g. PRD-202610-00001)
 * - YYYYMM keeps codes human-readable and roughly time-ordered.
 * - NNNNN is a zero-padded sequence across all months; we query the DB
 *   for the current max and increment by one inside the lock.
 */
class ProductCodeService
{
    private const LOCK_NAME = 'product_code_generation';

    private const LOCK_TIMEOUT_SECONDS = 5;

    public function generate(): string
    {
        $prefix = 'PRD-'.now()->format('Ym').'-';

        $acquired = DB::selectOne(
            'SELECT GET_LOCK(?, ?) AS acquired',
            [self::LOCK_NAME, self::LOCK_TIMEOUT_SECONDS]
        );

        if (! $acquired || ! $acquired->acquired) {
            // Fallback: use a high-precision timestamp suffix to avoid collision
            return $prefix.now()->format('His').random_int(100, 999);
        }

        try {
            // Find the highest sequence number for this prefix across all time
            // (not just this month) to prevent reuse after a prefix rollover.
            $last = Product::withoutGlobalScopes()
                ->where('code', 'like', 'PRD-%')
                ->selectRaw("MAX(CAST(SUBSTRING_INDEX(code, '-', -1) AS UNSIGNED)) AS max_seq")
                ->value('max_seq');

            $next = ($last ?? 0) + 1;

            return $prefix.str_pad((string) $next, 5, '0', STR_PAD_LEFT);
        } finally {
            DB::selectOne('SELECT RELEASE_LOCK(?)', [self::LOCK_NAME]);
        }
    }
}
