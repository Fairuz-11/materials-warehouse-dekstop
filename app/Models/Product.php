<?php

namespace App\Models;

use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'code', 'name', 'category_id', 'unit', 'barcode',
    'brand', 'purchase_price', 'selling_price',
    'min_stock', 'photo_path', 'is_active',
])]
class Product extends Model
{
    // current_stock is intentionally NOT fillable.
    // It must only be updated inside a DB transaction with lockForUpdate
    // via the stock service. Mass assignment must never touch it.

    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'purchase_price' => 'decimal:2',
            'selling_price' => 'decimal:2',
            'min_stock' => 'decimal:3',
            'current_stock' => 'decimal:3',
            'is_active' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function priceHistories(): HasMany
    {
        return $this->hasMany(ProductPriceHistory::class);
    }

    /** Scope: active products only. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** Scope: products at or below minimum stock (but not zero). */
    public function scopeLowStock(Builder $query): Builder
    {
        return $query->where('current_stock', '>', 0)
            ->whereColumn('current_stock', '<=', 'min_stock');
    }

    /** Scope: products with zero stock. */
    public function scopeOutOfStock(Builder $query): Builder
    {
        return $query->where('current_stock', 0);
    }

    /**
     * Check whether selling_price or purchase_price changed compared to given values.
     * Used by the update endpoint to decide whether to record a price history entry.
     */
    public function priceChangedFrom(?string $newSelling, ?string $newPurchase): bool
    {
        $sellingChanged = bccomp(
            (string) ($newSelling ?? '0'),
            (string) ($this->selling_price ?? '0'),
            2
        ) !== 0;

        $purchaseChanged = bccomp(
            (string) ($newPurchase ?? '0'),
            (string) ($this->purchase_price ?? '0'),
            2
        ) !== 0;

        // If both new values are null and both current values are null → no change
        if ($newSelling === null && $this->selling_price === null) {
            $sellingChanged = false;
        }
        if ($newPurchase === null && $this->purchase_price === null) {
            $purchaseChanged = false;
        }

        return $sellingChanged || $purchaseChanged;
    }

    /**
     * Whether the product has any transaction history.
     * Used to prevent permanent deletion.
     */
    public function hasTransactionHistory(): bool
    {
        return $this->priceHistories()->exists()
            || StockTransactionItem::where('product_id', $this->id)->exists();
    }
}
