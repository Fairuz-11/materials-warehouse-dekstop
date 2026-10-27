<?php

namespace App\Models;

use Database\Factories\StockTransactionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

#[Fillable([
    'transaction_number',
    'type',
    'status',
    'transaction_date',
    'note',
    'reason',
    'created_by',
    'confirmed_by',
    'confirmed_at',
    'idempotency_key',
    // stock_opname_id intentionally absent — set only by OpnameService in Step 6
])]
class StockTransaction extends Model
{
    /** @use HasFactory<StockTransactionFactory> */
    use HasFactory;

    // Valid values for type column
    public const TYPE_IN = 'stock_in';

    public const TYPE_OUT = 'stock_out';

    public const TYPE_CORRECTION = 'correction';

    public const TYPE_OPNAME = 'stock_opname';

    // Valid values for status column
    public const STATUS_DRAFT = 'draft';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_CANCELLED = 'cancelled';

    protected function casts(): array
    {
        return [
            'transaction_date' => 'date',
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
        return $this->hasMany(StockTransactionItem::class);
    }

    public function movements(): MorphMany
    {
        return $this->morphMany(StockMovement::class, 'source');
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
}
