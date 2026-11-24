<?php

namespace App\Http\Requests;

use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates an OUTFIT generation request: a captured lead, a set of catalog
 * product ids (the composition is validated separately by OutfitValidator), the
 * uploaded photo key and the recorded consent. Same lead gate as the single
 * endpoint — no contact, no generation, no spend — and the same consent gate: no
 * recorded consent, no processing.
 *
 * Shape only here (size 2..max, distinct, all exist). WHICH categories may combine
 * (no dress in a set, no two tops, wearing order) is domain logic and lives in
 * OutfitValidator so the rule set is reusable and testable on its own.
 */
class StoreOutfitGenerationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $max = (int) config('fitting_room.outfit.max_items', 2);

        return [
            'lead_id' => ['required', 'integer', 'exists:leads,id'],
            // Ownership proof for member leads (see StoreGenerationRequest).
            'lead_token' => ['nullable', 'string', 'max:128'],
            // A set: at least 2, at most the configured cap, no repeats, all real.
            'product_ids' => ['required', 'array', 'min:2', 'max:'.$max],
            // Only pieces the engine can dress (no shoes or headwear).
            'product_ids.*' => ['integer', 'distinct', Rule::exists('products', 'id')->whereIn('category', Product::TRY_ON_CATEGORIES)],
            'photo_key' => ['required', 'string', 'starts_with:'.config('fitting_room.photo.prefix').'/'],
            // Consent gate — see StoreGenerationRequest.
            'consent_id' => ! config('consent.required') ? ['exclude'] : [
                'required',
                'integer',
                Rule::exists('photo_consents', 'id')
                    ->where('consent_version', config('consent.version'))
                    ->where('terms_version', config('terms.version'))
                    ->whereNotNull('terms_accepted_at'),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $max = (int) config('fitting_room.outfit.max_items', 2);

        return [
            'lead_id.required' => __('requests.generation.lead_required'),
            'lead_id.integer' => __('requests.generation.lead_invalid'),
            'lead_id.exists' => __('requests.generation.lead_missing'),
            'product_ids.required' => __('requests.generation.products_required'),
            'product_ids.array' => __('requests.generation.products_required'),
            'product_ids.min' => __('requests.generation.products_min'),
            'product_ids.max' => __('requests.generation.products_max', ['max' => $max]),
            'product_ids.*.distinct' => __('requests.generation.products_distinct'),
            'product_ids.*.exists' => __('requests.generation.products_missing'),
            'photo_key.required' => __('requests.generation.photo_required'),
            'photo_key.starts_with' => __('requests.generation.photo_invalid'),
            'consent_id.required' => __('requests.generation.consent_required'),
            'consent_id.integer' => __('requests.generation.consent_invalid'),
            'consent_id.exists' => __('requests.generation.consent_missing'),
        ];
    }
}
