<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Immutable audit record — only created_at, no updated_at.
 *
 * quantity is signed: positive = stock_in, negative = stock_out.
 * stock_after = stock_before + quantity (always).
 *
 * source is polymorphic: currently App\Models\StockTransaction,
 * future App\Models\Sale.
 */
class StockMovement extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'product_id',
        'source_type',
        'source_id',
        'movement_type',
        'quantity',
        'stock_before',
        'stock_after',
        'reason',
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'stock_before' => 'decimal:3',
            'stock_after' => 'decimal:3',
            'created_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Polymorphic source — StockTransaction or future Sale. */
    public function source(): MorphTo
    {
        return $this->morphTo();
    }
}
