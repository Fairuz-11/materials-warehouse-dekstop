<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ConfirmStockOpnameRequest;
use App\Http\Requests\Api\V1\StoreStockOpnameRequest;
use App\Http\Requests\Api\V1\UpdateStockOpnameItemRequest;
use App\Models\StockOpname;
use App\Models\StockOpnameItem;
use App\Services\OpnameService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class StockOpnameController extends Controller
{
    public function __construct(
        private readonly OpnameService $opnameService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $opnames = StockOpname::query()
            ->with(['createdBy', 'confirmedBy'])
            ->withCount('items')
            ->when($request->filled('status'), function ($query) use ($request) {
                $query->where('status', $request->input('status'));
            })
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = $request->input('search');

                $query->where(function ($subQuery) use ($search) {
                    $subQuery
                        ->where('opname_number', 'like', "%{$search}%")
                        ->orWhere('note', 'like', "%{$search}%");
                });
            })
            ->orderByDesc('opname_date')
            ->orderByDesc('id')
            ->paginate(min(max((int) $request->input('per_page', 15), 1), 100));

        return response()->json([
            'success' => true,
            'message' => 'Daftar stock opname berhasil diambil.',
            'data' => $opnames,
        ]);
    }

    public function store(StoreStockOpnameRequest $request): JsonResponse
    {
        try {
            $opname = $this->opnameService->createOpname(
                $request->validated(),
                $request->user()
            );

            return response()->json([
                'success' => true,
                'message' => 'Stock opname berhasil dibuat.',
                'data' => $opname,
            ], 201);
        } catch (RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function show(StockOpname $stockOpname): JsonResponse
    {
        $stockOpname->load([
            'createdBy',
            'confirmedBy',
            'items.product',
            'adjustmentTransaction.items.product',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Detail stock opname berhasil diambil.',
            'data' => $stockOpname,
        ]);
    }

    public function updateItems(
        UpdateStockOpnameItemRequest $request,
        StockOpname $stockOpname
    ): JsonResponse {
        try {
            $updated = DB::transaction(function () use ($request, $stockOpname) {
                $results = [];

                foreach ($request->validated()['items'] as $itemData) {
                    $item = StockOpnameItem::query()
                        ->where('stock_opname_id', $stockOpname->id)
                        ->whereKey($itemData['id'])
                        ->firstOrFail();

                    $results[] = $this->opnameService->updateItem(
                        $stockOpname,
                        $item,
                        $itemData
                    );
                }

                return $results;
            });

            return response()->json([
                'success' => true,
                'message' => 'Hitungan fisik stock opname berhasil diperbarui.',
                'data' => $updated,
            ]);
        } catch (RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function confirm(
        ConfirmStockOpnameRequest $request,
        StockOpname $stockOpname
    ): JsonResponse {
        try {
            $confirmed = $this->opnameService->confirmOpname(
                $stockOpname,
                $request->user()
            );

            return response()->json([
                'success' => true,
                'message' => 'Stock opname berhasil dikonfirmasi.',
                'data' => $confirmed,
            ]);
        } catch (RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function cancel(StockOpname $stockOpname): JsonResponse
    {
        try {
            $cancelled = $this->opnameService->cancelOpname($stockOpname);

            return response()->json([
                'success' => true,
                'message' => 'Stock opname berhasil dibatalkan.',
                'data' => $cancelled,
            ]);
        } catch (RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }
}