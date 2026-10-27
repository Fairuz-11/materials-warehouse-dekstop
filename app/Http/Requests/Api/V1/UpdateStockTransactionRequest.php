<?php

namespace App\Http\Requests\Api\V1;

use App\Models\StockTransaction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStockTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var StockTransaction $tx */
        $tx = $this->route('stockTransaction');

        // Only admin_gudang may edit, and only draft transactions
        return $this->user()->isAdminGudang() && $tx->isDraft();
    }

    public function rules(): array
    {
        return [
            'transaction_date' => ['sometimes', 'date'],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'items' => ['sometimes', 'array', 'min:1'],
            'items.*.product_id' => [
                'required_with:items',
                'integer',
                Rule::exists('products', 'id')->where('is_active', true),
            ],
            'items.*.quantity' => ['required_with:items', 'numeric', 'gt:0', 'max:999999999999.999', 'decimal:0,3'],
            'items.*.purchase_price' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999999999.99', 'decimal:0,2'],
        ];
    }

    public function messages(): array
    {
        return [
            'items.*.product_id.exists' => 'Barang tidak ditemukan atau sudah tidak aktif.',
            'items.*.quantity.gt' => 'Kuantitas harus lebih dari nol.',
        ];
    }
}
