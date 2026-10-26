<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Category;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Category::class);
    }

    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:100',
                // Uniqueness is checked against the normalised value stored in DB.
                // The controller normalises before saving; we validate raw input here
                // and let the DB unique constraint be the final guard.
                Rule::unique('categories', 'name'),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Normalise category name: trim surrounding whitespace and collapse
     * internal runs of whitespace to a single space. This prevents near-
     * duplicate entries like "Besi  Beton" and "Besi Beton".
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('name')) {
            $this->merge([
                'name' => preg_replace('/\s+/', ' ', trim((string) $this->input('name'))),
            ]);
        }
    }
}
