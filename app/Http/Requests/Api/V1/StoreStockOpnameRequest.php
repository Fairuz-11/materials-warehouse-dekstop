<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the creation of a new stock opname (always starts as draft).
 *
 * physical_stock is optional on creation — items can be added without a
 * physical count yet.  The operator fills physical_stock later via the
 * update-items endpoint.
 *
 * Rules:
 *   - physical_stock omitted or null → stored as NULL (not yet counted)
 *   - physical_stock = 0             → valid (counted, result is zero)
 *   - physical_stock < 0             → rejected
 */
class StoreStockOpnameRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->isAdminGudang();
    }

    public function rules(): array
    {
        return [
            'opname_date' => ['required', 'date'],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'idempotency_key' => [
                'sometimes', 'nullable', 'string', 'max:100',
                Rule::unique('stock_opnames', 'idempotency_key'),
            ],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => [
                'required',
                'integer',
                Rule::exists('products', 'id')->where('is_active', true),
            ],
            // physical_stock: nullable — NULL means "not yet counted"
            // 0 is valid (counted, result is zero); negative values are rejected
            'items.*.physical_stock' => [
                'sometimes',
                'nullable',
                'numeric',
                'min:0',
                'max:999999999999.999',
                'decimal:0,3',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'items.required' => 'Opname harus memiliki setidaknya satu barang.',
            'items.min' => 'Opname harus memiliki setidaknya satu barang.',
            'items.*.product_id.exists' => 'Barang tidak ditemukan atau sudah tidak aktif.',
            'items.*.physical_stock.min' => 'Stok fisik tidak boleh negatif.',
            'items.*.physical_stock.decimal' => 'Stok fisik maksimal 3 angka desimal.',
        ];
    }
}
