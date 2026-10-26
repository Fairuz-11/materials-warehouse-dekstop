<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('item'));
    }

    public function rules(): array
    {
        /** @var Product $product */
        $product = $this->route('item');

        return [
            'code' => [
                'sometimes',
                'required',
                'string',
                'max:50',
                Rule::unique('products', 'code')->ignore($product->id),
            ],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'category_id' => [
                'sometimes',
                'required',
                'integer',
                Rule::exists('categories', 'id')->where('is_active', true),
            ],
            'unit' => ['sometimes', 'required', 'string', 'max:30'],
            'barcode' => [
                'sometimes',
                'nullable',
                'string',
                'max:100',
                Rule::unique('products', 'barcode')->ignore($product->id),
            ],
            'brand' => ['sometimes', 'nullable', 'string', 'max:100'],
            'purchase_price' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999999999.99', 'decimal:0,2'],
            'selling_price' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999999999.99', 'decimal:0,2'],
            'min_stock' => ['sometimes', 'numeric', 'min:0', 'max:999999999999.999', 'decimal:0,3'],
            'photo' => ['sometimes', 'nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:2048'],
            'is_active' => ['sometimes', 'boolean'],
            // current_stock is intentionally absent — must never be mass-assigned
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
