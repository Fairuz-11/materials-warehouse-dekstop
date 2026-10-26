<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Product::class);
    }

    public function rules(): array
    {
        return [
            // code is optional on create — generated automatically if omitted
            'code' => ['sometimes', 'nullable', 'string', 'max:50', Rule::unique('products', 'code')],
            'name' => ['required', 'string', 'max:255'],
            'category_id' => [
                'required',
                'integer',
                Rule::exists('categories', 'id')->where('is_active', true),
            ],
            'unit' => ['required', 'string', 'max:30'],
            'barcode' => ['sometimes', 'nullable', 'string', 'max:100', Rule::unique('products', 'barcode')],
            'brand' => ['sometimes', 'nullable', 'string', 'max:100'],
            // DECIMAL(15,2): max 13 integer digits + 2 decimal places
            'purchase_price' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999999999.99', 'decimal:0,2'],
            'selling_price' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999999999.99', 'decimal:0,2'],
            // DECIMAL(15,3): max 12 integer digits + 3 decimal places
            'min_stock' => ['sometimes', 'numeric', 'min:0', 'max:999999999999.999', 'decimal:0,3'],
            'photo' => ['sometimes', 'nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:2048'],
            'is_active' => ['sometimes', 'boolean'],
            // current_stock is never accepted — silently ignored via Fillable
        ];
    }

    public function messages(): array
    {
        return [
            'category_id.exists' => 'Kategori tidak ditemukan atau sudah tidak aktif.',
            'purchase_price.decimal' => 'Harga beli maksimal 2 angka desimal.',
            'selling_price.decimal' => 'Harga jual maksimal 2 angka desimal.',
            'min_stock.decimal' => 'Stok minimum maksimal 3 angka desimal.',
        ];
    }
}
