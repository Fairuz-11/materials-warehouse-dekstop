<?php

namespace App\Http\Requests\Api\V1;

use App\Models\StockOpname;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a confirm request for a stock opname.
 *
 * The business rule "all items must have physical_stock filled" is checked
 * in the service layer (OpnameService) inside the DB transaction, not here.
 * This request only validates the optional idempotency_key field.
 *
 * Why not check allItemsCounted() here?
 *   FormRequest.authorize() and rules() run before the DB transaction starts.
 *   The definitive check must happen inside lockForUpdate so no concurrent
 *   update can sneak in a NULL after this request passes validation.
 */
class ConfirmStockOpnameRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var StockOpname $opname */
        $opname = $this->route('stockOpname');

        return $this->user()->isAdminGudang()
            && ! $opname->isCancelled();
    }

    public function rules(): array
    {
        return [
            'idempotency_key' => [
                'sometimes',
                'nullable',
                'string',
                'max:100',
                // Only unique among non-confirmed — allow sending the same key again
                // to get idempotent response (checked in controller/service).
            ],
        ];
    }
}
