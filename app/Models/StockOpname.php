<?php

namespace App\Models;

use Database\Factories\StockOpnameFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'opname_number',
    'opname_date',
    'status',
    'note',
    'created_by',
    'confirmed_by',
    'confirmed_at',
    'idempotency_key',
])]
class StockOpname extends Model
{
    /** @use HasFactory<StockOpnameFactory> */
    use HasFactory;

    // Valid status values
    public const STATUS_DRAFT = 'draft';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_CANCELLED = 'cancelled';

    protected function casts(): array
    {
        return [
            'opname_date' => 'date',
            'confirmed_at' => 'datetime',
        ];
    }

    // ── Relationships ────────────────────────────────────────────────────

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockOpnameItem::class);
    }

    /**
     * The single adjustment transaction produced when this opname is confirmed.
     * UNIQUE constraint on stock_transactions.stock_opname_id enforces one-to-one.
     */
    public function adjustmentTransaction(): HasOne
    {
        return $this->hasOne(StockTransaction::class, 'stock_opname_id');
    }

    // ── Status helpers ────────────────────────────────────────────────────

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isConfirmed(): bool
    {
        return $this->status === self::STATUS_CONFIRMED;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    /**
     * True when every item has a non-null physical_stock value.
     * Required before confirmation is allowed.
     */
    public function allItemsCounted(): bool
    {
        if (! $this->relationLoaded('items')) {
            $this->load('items');
        }

        if ($this->items->isEmpty()) {
            return false;
        }

        return $this->items->every(fn (StockOpnameItem $item) => $item->physical_stock !== null);
    }
}
