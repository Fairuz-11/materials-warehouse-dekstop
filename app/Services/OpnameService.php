<?php

namespace App\Services;

use App\Models\Product;
use App\Models\StockMovement;
use App\Models\StockOpname;
use App\Models\StockOpnameItem;
use App\Models\StockTransaction;
use App\Models\StockTransactionItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class OpnameService
{
    public function __construct(
        private readonly TransactionNumberService $numberService,
        private readonly OpnameNumberService $opnameNumberService
    ) {}

    /**
     * Create a draft opname and snapshot current stock for each product.
     */
    public function createOpname(
        array $data,
        User $createdBy
    ): StockOpname {
        return DB::transaction(function () use ($data, $createdBy) {
            if (! empty($data['idempotency_key'])) {
                $existingOpname = StockOpname::where(
                    'idempotency_key',
                    $data['idempotency_key']
                )->first();

                if ($existingOpname) {
                    return $existingOpname->load([
                        'items.product',
                        'createdBy',
                    ]);
                }
            }
            $opname = $this->opnameNumberService->withNextNumber(
                function (string $opnameNumber) use ($data, $createdBy) {
                    return StockOpname::create([
                        'opname_number' => $opnameNumber,
                        'opname_date' => $data['opname_date'],
                        'status' => StockOpname::STATUS_DRAFT,
                        'note' => $data['note'] ?? null,
                        'created_by' => $createdBy->id,
                        'idempotency_key' => $data['idempotency_key'] ?? null,
                    ]);
                }
            );

            $productIds = collect($data['items'])
                ->pluck('product_id')
                ->unique()
                ->sort()
                ->values();

            foreach ($productIds as $productId) {
                $product = Product::query()
                    ->active()
                    ->findOrFail($productId);

                $inputItem = collect($data['items'])
                    ->firstWhere('product_id', $product->id);

                StockOpnameItem::create([
                    'stock_opname_id' => $opname->id,
                    'product_id' => $product->id,
                    'system_stock' => $product->current_stock,
                    'physical_stock' => $inputItem['physical_stock'] ?? null,
                    'difference_reason' => $inputItem['difference_reason'] ?? null,
                ]);
            }

            return $opname->load(['items.product', 'createdBy']);
        });
    }

    /**
     * Update a counted quantity on a draft opname.
     */
    public function updateItem(
        StockOpname $opname,
        StockOpnameItem $item,
        array $data
    ): StockOpnameItem {
        return DB::transaction(function () use ($opname, $item, $data) {
            $lockedOpname = StockOpname::query()
                ->lockForUpdate()
                ->findOrFail($opname->id);

            if (! $lockedOpname->isDraft()) {
                throw new RuntimeException(
                    'Opname hanya dapat diubah selama berstatus draft.'
                );
            }

            $lockedItem = StockOpnameItem::query()
                ->where('stock_opname_id', $lockedOpname->id)
                ->findOrFail($item->id);

            $lockedItem->update([
                'physical_stock' => $data['physical_stock'],
                'difference_reason' => $data['difference_reason'] ?? null,
            ]);

            return $lockedItem->fresh(['product', 'stockOpname']);
        });
    }

    /**
     * Confirm opname and apply its adjustments atomically.
     */
    public function confirmOpname(
        StockOpname $opname,
        User $confirmedBy
    ): StockOpname {
        return DB::transaction(function () use ($opname, $confirmedBy) {
            $lockedOpname = StockOpname::query()
                ->lockForUpdate()
                ->findOrFail($opname->id);

            if ($lockedOpname->isConfirmed()) {
                return $lockedOpname->load([
                    'items.product',
                    'createdBy',
                    'confirmedBy',
                    'adjustmentTransaction.items.product',
                ]);
            }

            if (! $lockedOpname->isDraft()) {
                throw new RuntimeException(
                    'Opname yang dibatalkan tidak dapat dikonfirmasi.'
                );
            }

            $items = $lockedOpname->items()
                ->orderBy('product_id')
                ->get();

            if ($items->isEmpty()) {
                throw new RuntimeException(
                    'Opname tidak memiliki item.'
                );
            }

            if (! $lockedOpname->allItemsCounted()) {
                throw new RuntimeException(
                    'Semua barang harus dihitung sebelum opname dikonfirmasi.'
                );
            }

            // Lock product rows in a stable order to reduce deadlock risk.
            $adjustments = [];

            foreach ($items as $item) {
                $product = Product::query()
                    ->lockForUpdate()
                    ->findOrFail($item->product_id);

                if (! $product->is_active) {
                    throw new RuntimeException(
                        "Barang '{$product->name}' sudah tidak aktif."
                    );
                }

                $actualStock = (float) $product->current_stock;
                $physicalStock = (float) $item->physical_stock;
                $difference = round($physicalStock - $actualStock, 3);

                if ($difference < 0 && $actualStock + $difference < 0) {
                    throw new RuntimeException(
                        "Penyesuaian akan membuat stok '{$product->name}' negatif."
                    );
                }

                $adjustments[] = [
                    'item' => $item,
                    'product' => $product,
                    'stock_before' => $actualStock,
                    'stock_after' => $physicalStock,
                    'quantity' => $difference,
                ];
            }

            // One adjustment transaction is associated with this opname.
            $transaction = new StockTransaction([
                'transaction_number' => $this->numberService->generate('stock_opname'),
                'type' => StockTransaction::TYPE_OPNAME,
                'status' => StockTransaction::STATUS_CONFIRMED,
                'transaction_date' => $lockedOpname->opname_date,
                'note' => $lockedOpname->note,
                'reason' => 'Penyesuaian hasil stock opname '.$lockedOpname->opname_number,
                'created_by' => $lockedOpname->created_by,
                'confirmed_by' => $confirmedBy->id,
                'confirmed_at' => now(),
            ]);

            $transaction->stock_opname_id = $lockedOpname->id;
            $transaction->save();

            foreach ($adjustments as $adjustment) {
                /** @var StockOpnameItem $item */
                $item = $adjustment['item'];

                /** @var Product $product */
                $product = $adjustment['product'];

                $before = $adjustment['stock_before'];
                $after = $adjustment['stock_after'];
                $quantity = $adjustment['quantity'];

                StockTransactionItem::create([
                    'stock_transaction_id' => $transaction->id,
                    'product_id' => $product->id,
                    'quantity' => $quantity,
                    'purchase_price' => $product->purchase_price,
                    'total_purchase' => null,
                    'stock_before' => $before,
                    'stock_after' => $after,
                ]);

                // Only create a movement when there is an actual change.
                // Use bccomp at 3dp so that physical_stock == actual_stock
                // (exact equality) produces no movement, consistent with
                // StockService which never creates zero-quantity movements.
                if (bccomp((string) $quantity, '0', 3) !== 0) {
                    StockMovement::create([
                        'product_id' => $product->id,
                        'source_type' => StockTransaction::class,
                        'source_id' => $transaction->id,
                        'movement_type' => StockTransaction::TYPE_OPNAME,
                        'quantity' => $quantity,
                        'stock_before' => $before,
                        'stock_after' => $after,
                        'reason' => $item->difference_reason
                            ?? $transaction->reason,
                        'user_id' => $confirmedBy->id,
                    ]);
                }

                // current_stock must never be mass-assigned.
                $product->getConnection()
                    ->table('products')
                    ->where('id', $product->id)
                    ->update(['current_stock' => $after]);
            }

            $lockedOpname->update([
                'status' => StockOpname::STATUS_CONFIRMED,
                'confirmed_by' => $confirmedBy->id,
                'confirmed_at' => now(),
            ]);

            return $lockedOpname->load([
                'items.product',
                'createdBy',
                'confirmedBy',
                'adjustmentTransaction.items.product',
            ]);
        });
    }

    /**
     * Cancel a draft opname.
     */
    public function cancelOpname(StockOpname $opname): StockOpname
    {
        return DB::transaction(function () use ($opname) {
            $lockedOpname = StockOpname::query()
                ->lockForUpdate()
                ->findOrFail($opname->id);

            if ($lockedOpname->isConfirmed()) {
                throw new RuntimeException(
                    'Opname yang sudah dikonfirmasi tidak dapat dibatalkan.'
                );
            }

            if ($lockedOpname->isCancelled()) {
                return $lockedOpname;
            }

            $lockedOpname->update([
                'status' => StockOpname::STATUS_CANCELLED,
            ]);

            return $lockedOpname->load(['items.product']);
        });
    }
}
