<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable audit record. No updated_at. Never mutated after creation.
 */
class ProductPriceHistory extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'product_id',
        'old_selling_price',
        'new_selling_price',
        'old_purchase_price',
        'new_purchase_price',
        'changed_by',
    ];

    protected function casts(): array
    {
        return [
            'old_selling_price' => 'decimal:2',
            'new_selling_price' => 'decimal:2',
            'old_purchase_price' => 'decimal:2',
            'new_purchase_price' => 'decimal:2',
            'created_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
