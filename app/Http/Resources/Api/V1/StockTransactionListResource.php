<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Lightweight serialisation for the paginated transaction list.
 * Omits items detail to keep responses lean.
 */
class StockTransactionListResource extends JsonResource
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
            'items_count' => $this->whenCounted('items'),
            'created_by' => [
                'id' => $this->createdBy?->id,
                'name' => $this->createdBy?->name,
            ],
            'confirmed_by' => $this->whenNotNull($this->confirmed_by, [
                'id' => $this->confirmedBy?->id,
                'name' => $this->confirmedBy?->name,
            ]),
            'confirmed_at' => $this->confirmed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
