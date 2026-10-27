<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\InsufficientStockException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreStockInRequest;
use App\Http\Requests\Api\V1\StoreStockOutRequest;
use App\Http\Requests\Api\V1\UpdateStockTransactionRequest;
use App\Http\Resources\Api\V1\StockTransactionListResource;
use App\Http\Resources\Api\V1\StockTransactionResource;
use App\Models\Product;
use App\Models\StockTransaction;
use App\Models\StockTransactionItem;
use App\Services\StockService;
use App\Services\TransactionNumberService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class StockTransactionController extends Controller
{
    public function __construct(
        private readonly StockService $stockService,
        private readonly TransactionNumberService $numberService,
    ) {}

    /**
     * GET /api/v1/stock-transactions
     *
     * Query params:
     *   type=stock_in|stock_out|correction
     *   status=draft|confirmed|cancelled
     *   date_from=YYYY-MM-DD
     *   date_to=YYYY-MM-DD
     *   per_page=15  (max 100)
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'type' => ['sometimes', 'string', 'in:stock_in,stock_out,correction,stock_opname'],
            'status' => ['sometimes', 'string', 'in:draft,confirmed,cancelled'],
            'date_from' => ['sometimes', 'date'],
            'date_to' => ['sometimes', 'date', 'after_or_equal:date_from'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = StockTransaction::with('createdBy')
            ->withCount('items')
            ->orderByDesc('transaction_date')
            ->orderByDesc('id');

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('date_from')) {
            $query->whereDate('transaction_date', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('transaction_date', '<=', $request->date_to);
        }

        $perPage = min((int) $request->input('per_page', 15), 100);

        return StockTransactionListResource::collection($query->paginate($perPage));
    }

    /**
     * POST /api/v1/stock-in
     * Creates a draft stock_in transaction with its items.
     * Stock does not change until confirmed.
     */
    public function storeIn(StoreStockInRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $transaction = $this->createTransaction(
            type: StockTransaction::TYPE_IN,
            validated: $validated,
            userId: $request->user()->id,
        );

        $transaction->load(['items.product', 'createdBy']);

        return response()->json([
            'message' => 'Transaksi barang masuk berhasil dibuat sebagai draft.',
            'transaction' => new StockTransactionResource($transaction),
        ], 201);
    }

    /**
     * POST /api/v1/stock-out
     * Creates a draft stock_out transaction.
     * Stock does not change until confirmed.
     * Preliminary stock check: if current stock is already zero for any item,
     * we reject early to surface the error before the DB transaction at confirm.
     */
    public function storeOut(StoreStockOutRequest $request): JsonResponse
    {
        $validated = $request->validated();

        // Preliminary (non-locking) stock check to give early feedback
        foreach ($validated['items'] as $item) {
            $product = Product::findOrFail($item['product_id']);
            if ((float) $product->current_stock < (float) $item['quantity']) {
                return response()->json([
                    'message' => "Stok barang '{$product->name}' tidak mencukupi. "
                        ."Stok saat ini: {$product->current_stock}, dibutuhkan: {$item['quantity']}.",
                ], 422);
            }
        }

        $transaction = $this->createTransaction(
            type: StockTransaction::TYPE_OUT,
            validated: $validated,
            userId: $request->user()->id,
        );

        $transaction->load(['items.product', 'createdBy']);

        return response()->json([
            'message' => 'Transaksi barang keluar berhasil dibuat sebagai draft.',
            'transaction' => new StockTransactionResource($transaction),
        ], 201);
    }

    /**
     * GET /api/v1/stock-transactions/{stockTransaction}
     */
    public function show(StockTransaction $stockTransaction): JsonResponse
    {
        $stockTransaction->load(['items.product', 'createdBy', 'confirmedBy']);

        return response()->json([
            'transaction' => new StockTransactionResource($stockTransaction),
        ]);
    }

    /**
     * PUT /api/v1/stock-transactions/{stockTransaction}
     * Only draft transactions can be edited.
     * Replaces all items when 'items' is provided.
     */
    public function update(UpdateStockTransactionRequest $request, StockTransaction $stockTransaction): JsonResponse
    {
        // authorize() in UpdateStockTransactionRequest already checks isDraft()
        // but if it somehow passed with a non-draft we guard here too
        if (! $stockTransaction->isDraft()) {
            return response()->json([
                'message' => 'Hanya transaksi berstatus draft yang dapat diubah.',
            ], 422);
        }

        $validated = $request->validated();

        // Update header fields
        $headerFields = array_intersect_key($validated, array_flip(['transaction_date', 'note', 'reason']));
        if (! empty($headerFields)) {
            $stockTransaction->update($headerFields);
        }

        // Replace items if provided
        if (isset($validated['items'])) {
            // cascadeOnDelete in migration handles deletion of old items
            $stockTransaction->items()->delete();
            $this->createItems($stockTransaction->id, $validated['items'], $stockTransaction->type);
        }

        $stockTransaction->load(['items.product', 'createdBy']);

        return response()->json([
            'message' => 'Transaksi berhasil diperbarui.',
            'transaction' => new StockTransactionResource($stockTransaction),
        ]);
    }

    /**
     * POST /api/v1/stock-transactions/{stockTransaction}/confirm
     *
     * Idempotency: if idempotency_key matches an already-confirmed transaction,
     * return 200 with the existing record without re-processing.
     * If no key, the status lock in StockService.confirmTransaction() prevents
     * double-processing.
     */
    public function confirm(Request $request, StockTransaction $stockTransaction): JsonResponse
    {
        $request->validate([
            'idempotency_key' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);

        // API-level idempotency: if the caller sends a key and we already have
        // a confirmed transaction with that key, return it without reprocessing.
        if ($request->filled('idempotency_key')) {
            $existing = StockTransaction::where('idempotency_key', $request->idempotency_key)
                ->where('status', StockTransaction::STATUS_CONFIRMED)
                ->first();

            if ($existing) {
                $existing->load(['items.product', 'createdBy', 'confirmedBy']);

                return response()->json([
                    'message' => 'Transaksi sudah dikonfirmasi sebelumnya (idempotent).',
                    'transaction' => new StockTransactionResource($existing),
                ]);
            }

            // Store the key on the transaction before confirming
            $stockTransaction->update(['idempotency_key' => $request->idempotency_key]);
        }

        // If already confirmed (no key provided but re-requested), return as-is
        if ($stockTransaction->isConfirmed()) {
            $stockTransaction->load(['items.product', 'createdBy', 'confirmedBy']);

            return response()->json([
                'message' => 'Transaksi sudah dikonfirmasi.',
                'transaction' => new StockTransactionResource($stockTransaction),
            ]);
        }

        if ($stockTransaction->isCancelled()) {
            return response()->json([
                'message' => 'Transaksi yang dibatalkan tidak dapat dikonfirmasi.',
            ], 422);
        }

        try {
            $confirmed = $this->stockService->confirmTransaction(
                $stockTransaction,
                $request->user()
            );
        } catch (InsufficientStockException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Transaksi berhasil dikonfirmasi.',
            'transaction' => new StockTransactionResource($confirmed),
        ]);
    }

    /**
     * POST /api/v1/stock-transactions/{stockTransaction}/cancel
     */
    public function cancel(Request $request, StockTransaction $stockTransaction): JsonResponse
    {
        if ($stockTransaction->isConfirmed()) {
            return response()->json([
                'message' => 'Transaksi yang sudah dikonfirmasi tidak dapat dibatalkan langsung. Gunakan transaksi koreksi.',
            ], 422);
        }

        if ($stockTransaction->isCancelled()) {
            return response()->json([
                'message' => 'Transaksi sudah berstatus dibatalkan.',
                'transaction' => new StockTransactionResource(
                    $stockTransaction->load(['items.product', 'createdBy'])
                ),
            ]);
        }

        try {
            $cancelled = $this->stockService->cancelTransaction($stockTransaction, $request->user());
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Transaksi berhasil dibatalkan.',
            'transaction' => new StockTransactionResource($cancelled),
        ]);
    }

    // ── Private helpers ──────────────────────────────────────────────────

    /**
     * Create a StockTransaction header + its items inside one DB transaction.
     * Stock is NOT changed here — only when confirmed.
     */
    private function createTransaction(string $type, array $validated, int $userId): StockTransaction
    {
        return DB::transaction(function () use ($type, $validated, $userId): StockTransaction {
            $transaction = StockTransaction::create([
                'transaction_number' => $this->numberService->generate($type),
                'type' => $type,
                'status' => StockTransaction::STATUS_DRAFT,
                'transaction_date' => $validated['transaction_date'],
                'note' => $validated['note'] ?? null,
                'reason' => $validated['reason'] ?? null,
                'created_by' => $userId,
                'idempotency_key' => $validated['idempotency_key'] ?? null,
            ]);

            $this->createItems($transaction->id, $validated['items'], $type);

            return $transaction;
        });
    }

    /**
     * Insert StockTransactionItem rows for a given transaction.
     * stock_before and stock_after are set to 0 as placeholders;
     * StockService fills the real values at confirmation time.
     */
    private function createItems(int $transactionId, array $items, string $type): void
    {
        foreach ($items as $item) {
            $quantity = (float) $item['quantity'];
            $purchasePrice = isset($item['purchase_price']) ? (float) $item['purchase_price'] : null;
            $totalPurchase = ($purchasePrice !== null) ? round($quantity * $purchasePrice, 2) : null;

            StockTransactionItem::create([
                'stock_transaction_id' => $transactionId,
                'product_id' => $item['product_id'],
                'quantity' => $quantity,
                'purchase_price' => $purchasePrice,
                'total_purchase' => $totalPurchase,
                'stock_before' => 0,
                'stock_after' => 0,
            ]);
        }
    }
}
