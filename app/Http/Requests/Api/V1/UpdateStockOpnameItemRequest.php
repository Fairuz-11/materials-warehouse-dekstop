<?php

namespace App\Http\Requests\Api\V1;

use App\Models\StockOpname;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates updating physical_stock values for items on a draft opname.
 *
 * Rules:
 *   - Parent opname must be draft (confirmed/cancelled are immutable)
 *   - physical_stock = null  → allowed (operator wants to clear a value)
 *   - physical_stock = 0     → valid (counted, result is zero)
 *   - physical_stock < 0     → rejected
 */
class UpdateStockOpnameItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var StockOpname $opname */
        $opname = $this->route('stockOpname');

        return $this->user()->isAdminGudang() && $opname->isDraft();
    }

    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['required', 'integer', 'exists:stock_opname_items,id'],
            'items.*.physical_stock' => [
                'present',          // must be present (even if null)
                'nullable',
                'numeric',
                'min:0',
                'max:999999999999.999',
                'decimal:0,3',
            ],
            'items.*.difference_reason' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'items.*.id.exists' => 'Item opname tidak ditemukan.',
            'items.*.physical_stock.present' => 'Kolom physical_stock wajib disertakan (boleh null).',
            'items.*.physical_stock.min' => 'Stok fisik tidak boleh negatif.',
            'items.*.physical_stock.decimal' => 'Stok fisik maksimal 3 angka desimal.',
        ];
    }
}
