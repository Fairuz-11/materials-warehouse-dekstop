<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreCategoryRequest;
use App\Http\Requests\Api\V1\UpdateCategoryRequest;
use App\Http\Resources\Api\V1\CategoryResource;
use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CategoryController extends Controller
{
    /**
     * GET /api/v1/categories
     *
     * admin_gudang: all categories (active + inactive)
     * admin_kasir:  active categories only (relevant for POS)
     *
     * Query params:
     *   is_active=true|false  — filter by status (admin_gudang only)
     *   per_page=15           — pagination size (default 15, max 100)
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Category::class);

        $query = Category::withCount('products')
            ->orderBy('name');

        if ($request->user()->isAdminKasir()) {
            $query->active();
        } elseif ($request->has('is_active')) {
            $query->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN));
        }

        $perPage = min((int) $request->input('per_page', 15), 100);

        return CategoryResource::collection($query->paginate($perPage));
    }

    /**
     * POST /api/v1/categories
     */
    public function store(StoreCategoryRequest $request): JsonResponse
    {
        $category = Category::create($request->validated());

        return response()->json([
            'message' => 'Kategori berhasil ditambahkan.',
            'category' => new CategoryResource($category->loadCount('products')),
        ], 201);
    }

    /**
     * GET /api/v1/categories/{category}
     */
    public function show(Request $request, Category $category): JsonResponse
    {
        $this->authorize('view', $category);

        return response()->json([
            'category' => new CategoryResource($category->loadCount('products')),
        ]);
    }

    /**
     * PUT /api/v1/categories/{category}
     */
    public function update(UpdateCategoryRequest $request, Category $category): JsonResponse
    {
        $category->update($request->validated());

        return response()->json([
            'message' => 'Kategori berhasil diperbarui.',
            'category' => new CategoryResource($category->loadCount('products')),
        ]);
    }

    /**
     * DELETE /api/v1/categories/{category}
     *
     * Never hard-deletes. Deactivates if category has products so that existing
     * product records retain their category reference. If the category has no
     * products at all and is being removed intentionally, we still only
     * deactivate — hard-delete is not exposed via the API.
     */
    public function destroy(Request $request, Category $category): JsonResponse
    {
        $this->authorize('delete', $category);

        if ($category->products()->exists()) {
            // Category is in use — deactivate only
            $category->update(['is_active' => false]);

            return response()->json([
                'message' => 'Kategori memiliki barang terkait dan telah dinonaktifkan.',
            ]);
        }

        // No products linked; deactivate and return 200 (consistent behaviour)
        $category->update(['is_active' => false]);

        return response()->json([
            'message' => 'Kategori berhasil dinonaktifkan.',
        ]);
    }
}
