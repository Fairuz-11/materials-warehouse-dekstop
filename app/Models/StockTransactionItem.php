<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Minimal model for stock_transaction_items.
 * Full CRUD implementation is in Step 5 (stock transactions).
 */
class StockTransactionItem extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'stock_transaction_id',
        'product_id',
        'quantity',
        'purchase_price',
        'total_purchase',
        'stock_before',
        'stock_after',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'purchase_price' => 'decimal:2',
            'total_purchase' => 'decimal:2',
            'stock_before' => 'decimal:3',
            'stock_after' => 'decimal:3',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function stockTransaction(): BelongsTo
    {
        return $this->belongsTo(StockTransaction::class);
    }
}
