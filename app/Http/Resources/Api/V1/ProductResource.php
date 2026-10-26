<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Full product detail including category and latest price history.
 */
class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'category' => new CategoryResource($this->whenLoaded('category')),
            'unit' => $this->unit,
            'barcode' => $this->barcode,
            'brand' => $this->brand,
            'purchase_price' => $this->purchase_price,
            'selling_price' => $this->selling_price,
            'min_stock' => $this->min_stock,
            'current_stock' => $this->current_stock,
            'photo_url' => $this->photo_path
                ? url('storage/'.$this->photo_path)
                : null,
            'is_active' => $this->is_active,
            'price_histories' => PriceHistoryResource::collection(
                $this->whenLoaded('priceHistories')
            ),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
