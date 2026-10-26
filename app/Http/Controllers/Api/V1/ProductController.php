<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreProductRequest;
use App\Http\Requests\Api\V1\UpdateProductRequest;
use App\Http\Resources\Api\V1\ProductListResource;
use App\Http\Resources\Api\V1\ProductResource;
use App\Models\Product;
use App\Models\ProductPriceHistory;
use App\Services\ProductCodeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;

class ProductController extends Controller
{
    public function __construct(private readonly ProductCodeService $codeService) {}

    /**
     * GET /api/v1/items
     *
     * Query params:
     *   category_id=1        — filter by category
     *   stock_condition=normal|low_stock|out_of_stock
     *   is_active=true|false — admin_gudang only; kasir always sees active only
     *   per_page=15          — max 100
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Product::class);

        $query = Product::with('category')
            ->orderBy('name');

        // Role-based visibility
        if ($request->user()->isAdminKasir()) {
            $query->active();
        } elseif ($request->has('is_active')) {
            $query->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN));
        }

        // Category filter
        if ($request->filled('category_id')) {
            $query->where('category_id', (int) $request->category_id);
        }

        // Stock condition filter
        match ($request->input('stock_condition')) {
            'low_stock' => $query->lowStock(),
            'out_of_stock' => $query->outOfStock(),
            default => null,
        };

        $perPage = min((int) $request->input('per_page', 15), 100);

        return ProductListResource::collection($query->paginate($perPage));
    }

    /**
     * POST /api/v1/items
     */
    public function store(StoreProductRequest $request): JsonResponse
    {
        $validated = $request->validated();

        // Auto-generate code if not provided
        if (empty($validated['code'])) {
            $validated['code'] = $this->codeService->generate();
        }

        // current_stock is always 0 on creation — enforced by model Fillable
        $validated['current_stock'] = 0;

        // Handle photo upload
        if ($request->hasFile('photo')) {
            $validated['photo_path'] = $request->file('photo')
                ->store('products', 'public');
        }
        unset($validated['photo']);

        $product = Product::create($validated);
        $product->load('category');

        return response()->json([
            'message' => 'Barang berhasil ditambahkan.',
            'item' => new ProductResource($product),
        ], 201);
    }

    /**
     * GET /api/v1/items/{item}
     */
    public function show(Request $request, Product $item): JsonResponse
    {
        $this->authorize('view', $item);

        $item->load(['category', 'priceHistories.changedBy']);

        return response()->json([
            'item' => new ProductResource($item),
        ]);
    }

    /**
     * PUT /api/v1/items/{item}
     *
     * Price history is recorded when selling_price or purchase_price changes.
     * current_stock is never accepted — not in validated() because it is absent
     * from UpdateProductRequest rules.
     */
    public function update(UpdateProductRequest $request, Product $item): JsonResponse
    {
        $validated = $request->validated();

        // Detect price change before updating model
        $newSelling = array_key_exists('selling_price', $validated)
            ? $validated['selling_price']
            : null;
        $newPurchase = array_key_exists('purchase_price', $validated)
            ? $validated['purchase_price']
            : null;

        $priceChanged = false;
        $oldSelling = $item->selling_price;
        $oldPurchase = $item->purchase_price;

        // Only record history when a price field was explicitly submitted
        if (array_key_exists('selling_price', $validated) || array_key_exists('purchase_price', $validated)) {
            $priceChanged = $item->priceChangedFrom(
                array_key_exists('selling_price', $validated) ? (string) ($validated['selling_price'] ?? '') : null,
                array_key_exists('purchase_price', $validated) ? (string) ($validated['purchase_price'] ?? '') : null,
            );
        }

        // Handle photo upload: delete old file first to avoid orphans
        if ($request->hasFile('photo')) {
            if ($item->photo_path) {
                Storage::disk('public')->delete($item->photo_path);
            }
            $validated['photo_path'] = $request->file('photo')
                ->store('products', 'public');
        }
        unset($validated['photo']);

        // current_stock must never be in $validated, but guard explicitly
        unset($validated['current_stock']);

        $item->update($validated);

        // Record price history after successful update
        if ($priceChanged) {
            ProductPriceHistory::create([
                'product_id' => $item->id,
                'old_selling_price' => $oldSelling,
                'new_selling_price' => $item->fresh()->selling_price,
                'old_purchase_price' => $oldPurchase,
                'new_purchase_price' => $item->fresh()->purchase_price,
                'changed_by' => $request->user()->id,
            ]);
        }

        $item->load(['category', 'priceHistories.changedBy']);

        return response()->json([
            'message' => 'Barang berhasil diperbarui.',
            'item' => new ProductResource($item),
        ]);
    }

    /**
     * DELETE /api/v1/items/{item}
     *
     * Deactivates the product. Hard-delete is blocked if any transaction
     * history exists. If no history exists we still only deactivate
     * (consistent behaviour, avoids accidental data loss).
     */
    public function destroy(Request $request, Product $item): JsonResponse
    {
        $this->authorize('delete', $item);

        $item->update(['is_active' => false]);

        return response()->json([
            'message' => 'Barang berhasil dinonaktifkan.',
        ]);
    }

    /**
     * GET /api/v1/items/search
     *
     * Searches name, code, and barcode using safe LIKE with parameterised
     * bindings — no raw user input in the query string.
     *
     * Query params:
     *   q=string             — search term (min 1 char)
     *   category_id=1        — optional category filter
     *   per_page=15
     */
    public function search(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Product::class);

        $request->validate([
            'q' => ['required', 'string', 'min:1', 'max:100'],
            'category_id' => ['sometimes', 'integer'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $term = $request->input('q');

        $query = Product::with('category')
            ->where(function ($q) use ($term) {
                $q->where('name', 'like', '%'.$term.'%')
                    ->orWhere('code', 'like', '%'.$term.'%')
                    ->orWhere('barcode', 'like', '%'.$term.'%');
            });

        // Role-based visibility
        if ($request->user()->isAdminKasir()) {
            $query->active();
        }

        if ($request->filled('category_id')) {
            $query->where('category_id', (int) $request->category_id);
        }

        $perPage = min((int) $request->input('per_page', 15), 100);

        return ProductListResource::collection($query->paginate($perPage));
    }
}
