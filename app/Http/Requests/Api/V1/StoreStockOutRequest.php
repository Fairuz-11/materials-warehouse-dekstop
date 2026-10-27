<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStockOutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->isAdminGudang();
    }

    public function rules(): array
    {
        return [
            'transaction_date' => ['required', 'date'],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'idempotency_key' => [
                'sometimes', 'nullable', 'string', 'max:100',
                Rule::unique('stock_transactions', 'idempotency_key'),
            ],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => [
                'required',
                'integer',
                Rule::exists('products', 'id')->where('is_active', true),
            ],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:999999999999.999', 'decimal:0,3'],
            // purchase_price and total_purchase are not relevant for stock_out
        ];
    }

    public function messages(): array
    {
        return [
            'items.required' => 'Transaksi harus memiliki setidaknya satu barang.',
            'items.min' => 'Transaksi harus memiliki setidaknya satu barang.',
            'items.*.product_id.exists' => 'Barang tidak ditemukan atau sudah tidak aktif.',
            'items.*.quantity.gt' => 'Kuantitas harus lebih dari nol.',
            'items.*.quantity.decimal' => 'Kuantitas maksimal 3 angka desimal.',
        ];
    }
}
