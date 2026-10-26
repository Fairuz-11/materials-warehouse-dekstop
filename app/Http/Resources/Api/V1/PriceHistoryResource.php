<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PriceHistoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'old_selling_price' => $this->old_selling_price,
            'new_selling_price' => $this->new_selling_price,
            'old_purchase_price' => $this->old_purchase_price,
            'new_purchase_price' => $this->new_purchase_price,
            'changed_by' => [
                'id' => $this->changedBy?->id,
                'name' => $this->changedBy?->name,
            ],
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
