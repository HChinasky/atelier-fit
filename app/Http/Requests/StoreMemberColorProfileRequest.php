<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A member's own colour analysis: a face photo, one question, and consent.
 *
 * Consent is REQUIRED, not defaulted: the photo is analysed for a purpose of
 * its own (a colour season, not a try-on), and the privacy policy says that
 * happens only when the person asks for it. The single quiz question is the
 * one that changes the answer — dyed hair is left out of the measurement —
 * and it stays optional: the photo decides.
 *
 * Size and format limits are the engine's own (config/color_analysis.php);
 * the engine has the real floors and refuses with a reason the page can word.
 */
class StoreMemberColorProfileRequest extends FormRequest
{
    /**
     * The route already carries both feature flags and auth.
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
            'photo' => [
                'required',
                'file',
                'mimes:'.implode(',', (array) config('color_analysis.upload.mimes')),
                'max:'.(int) config('color_analysis.upload.max_kilobytes'),
            ],
            'natural_hair' => ['nullable', 'boolean'],
            // Only meaningful for dyed hair; a note for the stylist, never read by the classification.
            'previous_hair' => ['nullable', 'in:light,medium,dark,red,grey'],
            // Switched off (config/consent.php): no box, nothing to accept.
            'consent' => config('consent.required') ? ['accepted'] : ['exclude'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'photo.required' => __('requests.color.photo_required'),
            'photo.uploaded' => __('requests.photo.failed'),
            'photo.file' => __('requests.color.photo_type'),
            'photo.mimes' => __('requests.color.photo_type'),
            'photo.max' => __('requests.color.photo_max'),
            'consent.accepted' => __('requests.color.consent'),
        ];
    }

    /**
     * The quiz as the engine wants it, or null when nothing was answered.
     *
     * @return array<string, bool|string>|null
     */
    public function quiz(): ?array
    {
        if (! $this->has('natural_hair') || $this->input('natural_hair') === '' || $this->input('natural_hair') === null) {
            return null;
        }

        $quiz = ['natural_hair' => $this->boolean('natural_hair')];

        // What the hair was before the dye is asked only of dyed hair.
        if ($quiz['natural_hair'] === false && in_array($this->input('previous_hair'), ['light', 'medium', 'dark', 'red', 'grey'], true)) {
            $quiz['previous_hair'] = $this->input('previous_hair');
        }

        return $quiz;
    }
}
