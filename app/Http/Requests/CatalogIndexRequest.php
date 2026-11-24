<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the public catalog query string. Keeps the controller thin and
 * rejects bad input (unknown category, out-of-range page size) with a 422
 * instead of silently ignoring it.
 */
class CatalogIndexRequest extends FormRequest
{
    /**
     * Public endpoint — no authorization.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Optional filter, restricted to our FASHN category enum.
            'category' => ['sometimes', Rule::in(['upper_body', 'lower_body', 'dresses'])],
            // Optional page-size override, bounded to keep payloads sane.
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
