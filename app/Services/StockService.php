<?php

namespace App\Services;

use App\Exceptions\InsufficientStockException;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\StockTransaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Single service responsible for ALL stock mutations.
 *
 * No controller, job, or other service may update products.current_stock
 * directly. Every change goes through confirmTransaction() which enforces
 * the 8-step consistency contract defined in the migration comment.
 *
 * CONSISTENCY CONTRACT (per migration comment):
 * 1. Begin DB transaction
 * 2. SELECT … FOR UPDATE on each product row (lockForUpdate)
 * 3. Read current_stock as stock_before
 * 4. Compute stock_after = stock_before + signed_quantity
 * 5. Reject if stock_after < 0 (InsufficientStockException)
 * 6. Insert stock_movement with stock_before / stock_after
 * 7. Update products.current_stock = stock_after
 * 8. Transition StockTransaction status to 'confirmed'
 * 9. Commit (or rollback on any exception)
 *
 * IDEMPOTENCY:
 * The confirm endpoint passes an optional idempotency_key. Before doing any
 * work we check status is still 'draft' inside a lockForUpdate on the
 * transaction row itself, so concurrent confirmations for the same transaction
 * are safe — only the first one proceeds.
 */
class StockService
{
    /**
     * Confirm a draft transaction and apply all stock changes atomically.
     *
     * @throws InsufficientStockException when stock_out would make any product negative
     * @throws RuntimeException when transaction is not in draft status
     */
    public function confirmTransaction(StockTransaction $transaction, User $confirmedBy): StockTransaction
    {
        return DB::transaction(function () use ($transaction, $confirmedBy): StockTransaction {
            // Step 1: Lock the transaction row to prevent concurrent confirmations
            /** @var StockTransaction $tx */
            $tx = StockTransaction::lockForUpdate()->findOrFail($transaction->id);

            if (! $tx->isDraft()) {
                // Already confirmed or cancelled — idempotent: return as-is
                // (the controller will detect this and return the current state)
                return $tx->load(['items.product', 'createdBy', 'confirmedBy']);
            }

            // Step 2: Load items (the transaction must have at least one)
            $items = $tx->items()->with('product')->get();

            if ($items->isEmpty()) {
                throw new RuntimeException('Transaksi tidak memiliki item. Tambahkan barang sebelum mengonfirmasi.');
            }

            // Steps 3–7: For each item, lock the product row and apply change
            foreach ($items as $item) {
                /** @var Product $product */
                $product = Product::lockForUpdate()->findOrFail($item->product_id);

                if (! $product->is_active) {
                    throw new RuntimeException("Barang '{$product->name}' sudah tidak aktif dan tidak dapat ditransaksikan.");
                }

                $stockBefore = (float) $product->current_stock;
                $signedQty = $this->signedQuantity($tx->type, (float) $item->quantity);
                $stockAfter = $stockBefore + $signedQty;

                if ($stockAfter < 0) {
                    throw new InsufficientStockException(
                        "Stok barang '{$product->name}' tidak mencukupi. "
                        ."Stok saat ini: {$stockBefore}, dibutuhkan: ".abs($signedQty).'.'
                    );
                }

                // Step 5b: update item snapshot (stock_before / stock_after)
                $item->update([
                    'stock_before' => $stockBefore,
                    'stock_after' => $stockAfter,
                ]);

                // Step 6: Insert stock_movement
                StockMovement::create([
                    'product_id' => $product->id,
                    'source_type' => StockTransaction::class,
                    'source_id' => $tx->id,
                    'movement_type' => $tx->type,
                    'quantity' => $signedQty,
                    'stock_before' => $stockBefore,
                    'stock_after' => $stockAfter,
                    'reason' => $tx->reason,
                    'user_id' => $confirmedBy->id,
                ]);

                // Step 7: Update product stock
                $product->getConnection()
                    ->table('products')
                    ->where('id', $product->id)
                    ->update(['current_stock' => $stockAfter]);
            }

            // Step 8: Transition status
            $tx->update([
                'status' => StockTransaction::STATUS_CONFIRMED,
                'confirmed_by' => $confirmedBy->id,
                'confirmed_at' => now(),
            ]);

            return $tx->load(['items.product', 'createdBy', 'confirmedBy']);
        });
    }

    /**
     * Cancel a draft transaction.
     * Confirmed transactions cannot be cancelled through this method;
     * a correction transaction must be used instead.
     *
     * @throws RuntimeException when transaction is already confirmed
     */
    public function cancelTransaction(StockTransaction $transaction, User $cancelledBy): StockTransaction
    {
        return DB::transaction(function () use ($transaction): StockTransaction {
            /** @var StockTransaction $tx */
            $tx = StockTransaction::lockForUpdate()->findOrFail($transaction->id);

            if ($tx->isConfirmed()) {
                throw new RuntimeException(
                    'Transaksi yang sudah dikonfirmasi tidak dapat dibatalkan langsung. '
                    .'Gunakan transaksi koreksi.'
                );
            }

            if ($tx->isCancelled()) {
                return $tx->load(['items.product', 'createdBy', 'confirmedBy']);
            }

            $tx->update(['status' => StockTransaction::STATUS_CANCELLED]);

            return $tx->load(['items.product', 'createdBy', 'confirmedBy']);
        });
    }

    /**
     * Convert a raw positive quantity to its signed form based on transaction type.
     * stock_in / correction-positive → positive
     * stock_out / correction-negative → negative
     */
    private function signedQuantity(string $type, float $rawQuantity): float
    {
        return match ($type) {
            StockTransaction::TYPE_OUT => -abs($rawQuantity),
            default => abs($rawQuantity),
        };
    }
}
