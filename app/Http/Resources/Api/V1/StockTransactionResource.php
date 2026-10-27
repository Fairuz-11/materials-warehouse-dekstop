<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Full transaction detail including items.
 */
class StockTransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'transaction_number' => $this->transaction_number,
            'type' => $this->type,
            'status' => $this->status,
            'transaction_date' => $this->transaction_date?->toDateString(),
            'note' => $this->note,
            'reason' => $this->reason,
            'idempotency_key' => $this->idempotency_key,
            'items' => $this->whenLoaded('items', function () {
                return $this->items->map(fn ($item) => [
                    'id' => $item->id,
                    'product' => [
                        'id' => $item->product?->id,
                        'code' => $item->product?->code,
                        'name' => $item->product?->name,
                        'unit' => $item->product?->unit,
                    ],
                    'quantity' => $item->quantity,
                    'purchase_price' => $item->purchase_price,
                    'total_purchase' => $item->total_purchase,
                    'stock_before' => $item->stock_before,
                    'stock_after' => $item->stock_after,
                ]);
            }),
            'created_by' => [
                'id' => $this->createdBy?->id,
                'name' => $this->createdBy?->name,
            ],
            'confirmed_by' => $this->confirmed_by ? [
                'id' => $this->confirmedBy?->id,
                'name' => $this->confirmedBy?->name,
            ] : null,
            'confirmed_at' => $this->confirmed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
