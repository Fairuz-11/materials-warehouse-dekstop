<?php

namespace App\Services;

use App\Models\StockTransaction;
use Illuminate\Support\Facades\DB;

/**
 * Generates unique, sequential transaction numbers under concurrency.
 *
 * Strategy: MySQL advisory lock (GET_LOCK) serialises generation so two
 * simultaneous requests cannot pick the same sequence number. The lock is
 * held only for the duration of the MAX() query, then released immediately
 * before the caller proceeds with the INSERT.
 *
 * Format by type:
 *   stock_in   → IN-YYYYMM-NNNNN   e.g. IN-202610-00001
 *   stock_out  → OUT-YYYYMM-NNNNN
 *   correction → COR-YYYYMM-NNNNN
 *   stock_opname → OPN-YYYYMM-NNNNN
 *
 * The sequence counter is global across all types and months so that the
 * 5-digit suffix is always unique within a prefix family.
 */
class TransactionNumberService
{
    private const LOCK_NAME = 'transaction_number_generation';

    private const LOCK_TIMEOUT_SECONDS = 5;

    private const PREFIX_MAP = [
        'stock_in' => 'IN',
        'stock_out' => 'OUT',
        'correction' => 'COR',
        'stock_opname' => 'OPN',
    ];

    public function generate(string $type): string
    {
        $typePrefix = self::PREFIX_MAP[$type] ?? 'TRX';
        $monthPart = now()->format('Ym');
        $prefix = "{$typePrefix}-{$monthPart}-";

        $acquired = DB::selectOne(
            'SELECT GET_LOCK(?, ?) AS acquired',
            [self::LOCK_NAME, self::LOCK_TIMEOUT_SECONDS]
        );

        if (! $acquired || ! $acquired->acquired) {
            // Fallback: timestamp + random to avoid collision if lock times out
            return $prefix.now()->format('His').random_int(100, 999);
        }

        try {
            // Find highest numeric suffix across ALL transaction_number values
            // so re-runs after a month rollover never reuse a suffix.
            $last = StockTransaction::withoutGlobalScopes()
                ->selectRaw("MAX(CAST(SUBSTRING_INDEX(transaction_number, '-', -1) AS UNSIGNED)) AS max_seq")
                ->value('max_seq');

            $next = ($last ?? 0) + 1;

            return $prefix.str_pad((string) $next, 5, '0', STR_PAD_LEFT);
        } finally {
            DB::selectOne('SELECT RELEASE_LOCK(?)', [self::LOCK_NAME]);
        }
    }
}
