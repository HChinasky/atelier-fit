<?php

namespace App\Http\Requests;

use App\Services\Onboarding\StyleQuiz;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The style quiz as submitted. Every question is optional; an answer that is
 * given has to be one of the question's own options.
 *
 * The last step may carry the face photo. It is optional too, but a photo
 * comes with the consent tick or not at all — the same limits and the same
 * words as the colour page (StoreMemberColorProfileRequest).
 */
class StoreStyleQuizRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = [];

        foreach (StyleQuiz::questions() as $key => $question) {
            $options = array_map('strval', array_keys($question['options']));

            if ($question['multiple']) {
                $rules[$key] = ['nullable', 'array'];
                $rules[$key.'.*'] = ['string', Rule::in($options)];
            } else {
                $rules[$key] = ['nullable', 'string', Rule::in($options)];
            }
        }

        $rules['photo'] = [
            'nullable',
            'file',
            'mimes:'.implode(',', (array) config('color_analysis.upload.mimes')),
            'max:'.(int) config('color_analysis.upload.max_kilobytes'),
        ];
        $rules['consent'] = config('consent.required') ? ['exclude_without:photo', 'accepted'] : ['exclude'];

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'photo.uploaded' => __('requests.photo.failed'),
            'photo.file' => __('requests.color.photo_type'),
            'photo.mimes' => __('requests.color.photo_type'),
            'photo.max' => __('requests.color.photo_max'),
            'consent.accepted' => __('requests.color.consent'),
        ];
    }

    /**
     * @return array<string, bool|string|list<string>>
     */
    public function answers(): array
    {
        return StyleQuiz::normalise($this->validated());
    }
}
