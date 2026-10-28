<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStockOpnameRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdminGudang() ?? false;
    }

    public function rules(): array
    {
        return [
            'opname_date' => ['required', 'date'],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],

            // Idempotency ditangani oleh service, bukan ditolak saat validasi.
            'idempotency_key' => [
                'sometimes',
                'nullable',
                'string',
                'max:100',
            ],

            'items' => ['required', 'array', 'min:1'],

            'items.*.product_id' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('products', 'id')
                    ->where('is_active', true),
            ],

            'items.*.physical_stock' => [
                'sometimes',
                'nullable',
                'numeric',
                'min:0',
                'max:999999999999.999',
                'decimal:0,3',
            ],

            'items.*.difference_reason' => [
                'sometimes',
                'nullable',
                'string',
                'max:1000',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'items.required' => 'Opname harus memiliki setidaknya satu barang.',
            'items.min' => 'Opname harus memiliki setidaknya satu barang.',
            'items.*.product_id.distinct' => 'Barang yang sama tidak boleh dimasukkan lebih dari sekali.',
            'items.*.product_id.exists' => 'Barang tidak ditemukan atau sudah tidak aktif.',
            'items.*.physical_stock.min' => 'Stok fisik tidak boleh negatif.',
            'items.*.physical_stock.decimal' => 'Stok fisik maksimal 3 angka desimal.',
        ];
    }
};