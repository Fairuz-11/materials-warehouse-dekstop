<?php

namespace App\Services;

use App\Models\StockOpname;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class OpnameNumberService
{
    private const LOCK_NAME = 'stock_opname_number_generation';

    private const LOCK_TIMEOUT_SECONDS = 5;

    /**
     * Jalankan callback saat lock penomoran masih dipegang.
     * Lock dilepas setelah callback selesai, termasuk jika terjadi error.
     */
    public function withNextNumber(callable $callback): mixed
    {
        $acquired = DB::selectOne(
            'SELECT GET_LOCK(?, ?) AS acquired',
            [self::LOCK_NAME, self::LOCK_TIMEOUT_SECONDS]
        );

        if (! $acquired || (int) $acquired->acquired !== 1) {
            throw new RuntimeException(
                'Gagal mendapatkan kunci untuk membuat nomor stock opname.'
            );
        }

        try {
            $monthPart = now()->format('Ym');
            $prefix = "OPN-{$monthPart}-";

            $last = StockOpname::query()
                ->where('opname_number', 'like', $prefix.'%')
                ->selectRaw(
                    "MAX(CAST(SUBSTRING_INDEX(opname_number, '-', -1) AS UNSIGNED)) AS max_seq"
                )
                ->value('max_seq');

            $number = $prefix.str_pad(
                (string) (((int) $last) + 1),
                5,
                '0',
                STR_PAD_LEFT
            );

            return $callback($number);
        } finally {
            DB::selectOne(
                'SELECT RELEASE_LOCK(?)',
                [self::LOCK_NAME]
            );
        }
    }
}