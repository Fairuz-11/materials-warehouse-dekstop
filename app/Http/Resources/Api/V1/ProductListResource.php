<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Lightweight serialisation for the paginated product list.
 * Omits price histories and category detail to keep list responses lean.
 */
class ProductListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'category' => [
                'id' => $this->category?->id,
                'name' => $this->category?->name,
            ],
            'unit' => $this->unit,
            'barcode' => $this->barcode,
            'brand' => $this->brand,
            'selling_price' => $this->selling_price,
            'purchase_price' => $this->purchase_price,
            'min_stock' => $this->min_stock,
            'current_stock' => $this->current_stock,
            'is_active' => $this->is_active,
            'photo_url' => $this->photo_path
                ? url('storage/'.$this->photo_path)
                : null,
        ];
    }
}
