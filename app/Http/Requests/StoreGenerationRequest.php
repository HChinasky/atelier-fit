<?php

namespace App\Http\Requests;

use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a generation request. lead_id is REQUIRED and must exist — this is
 * the lead gate at the edge: no captured contact means no generation (and so no
 * FASHN spend), rejected before the controller or any provider call.
 *
 * consent_id is required for the same reason in the other direction: no recorded
 * consent means the photo may not be processed, whoever is asking.
 */
class StoreGenerationRequest extends FormRequest
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
        return [
            // Lead gate: a generation cannot start without a captured lead.
            'lead_id' => ['required', 'integer', 'exists:leads,id'],
            // Ownership proof, REQUIRED whenever the lead belongs to a member
            // (enforced in the controller via LeadToken — member generations
            // debit that member's credits, so the lead id alone must not do).
            'lead_token' => ['nullable', 'string', 'max:128'],
            // Only a piece the engine can dress: shoes and headwear are in
            // the catalog for capsules, not for try-on.
            'product_id' => ['required', 'integer', Rule::exists('products', 'id')->whereIn('category', Product::TRY_ON_CATEGORIES)],
            // The R2 key returned by the upload step, under the private prefix.
            'photo_key' => ['required', 'string', 'starts_with:'.config('fitting_room.photo.prefix').'/'],
            // Consent gate, defence in depth: the upload endpoint already refuses a
            // photo without consent, and the generation refuses to USE one. The
            // record must also match the wording currently in force — consent to
            // superseded text authorises nothing.
            // Switched off (config/consent.php), there is no record to carry.
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
     * User-facing validation messages, in the visitor's language
     * (lang/<locale>/requests.php).
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lead_id.required' => __('requests.generation.lead_required'),
            'lead_id.integer' => __('requests.generation.lead_invalid'),
            'lead_id.exists' => __('requests.generation.lead_missing'),
            'product_id.required' => __('requests.generation.product_required'),
            'product_id.integer' => __('requests.generation.product_invalid'),
            'product_id.exists' => __('requests.generation.product_missing'),
            'photo_key.required' => __('requests.generation.photo_required'),
            'photo_key.string' => __('requests.generation.photo_invalid'),
            'photo_key.starts_with' => __('requests.generation.photo_invalid'),
            'consent_id.required' => __('requests.generation.consent_required'),
            'consent_id.integer' => __('requests.generation.consent_invalid'),
            'consent_id.exists' => __('requests.generation.consent_missing'),
        ];
    }

    /**
     * Ukrainian field names for any default messages not covered above.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return (array) __('generation.attributes');
    }
}
