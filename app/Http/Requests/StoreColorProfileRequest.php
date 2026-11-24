<?php

namespace App\Http\Requests;

use App\Models\ColorProfile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The admin colour-analysis upload: a photo, and optionally what the person
 * says about themselves.
 *
 * The quiz mirrors the engine's own Quiz contract exactly — same four fields,
 * same allowed values — because it is forwarded to it verbatim. Every one is
 * optional: the photo decides, the quiz only breaks ties once a classifier
 * exists, and there is no classifier yet.
 *
 * Deliberately permissive on size and dimensions. The engine has its own
 * absolute floors (face width and iris diameter in source pixels) and they are
 * the ones that decide whether a photo can be measured; a second, different
 * opinion here would refuse photos the engine would have been happy with.
 */
class StoreColorProfileRequest extends FormRequest
{
    /**
     * The route already carries the feature flag, auth and the admin check.
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

            // Engine Quiz fields. Null is a real answer here: "not stated".
            'natural_hair' => ['nullable', 'boolean'],
            'metal' => ['nullable', 'in:gold,silver,both'],
            'tanning' => ['nullable', 'in:burns,tans,both'],
            'veins' => ['nullable', 'in:blue,green,mixed'],
            // Which person and what light, for calibration. Both free and
            // both optional: a one-off analysis has no use for either, and a
            // calibration run types them off the sheet next to the camera.
            'person_ref' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_\-]+$/'],
            'lighting' => ['nullable', Rule::in(ColorProfile::LIGHTINGS)],
        ];
    }

    /**
     * The quiz as the engine wants it, or null when nothing was answered.
     *
     * Unanswered fields are dropped rather than sent as null: the engine's Quiz
     * forbids unknown keys and treats an absent one as "not stated", which is
     * the same thing said more briefly.
     *
     * @return array<string, mixed>|null
     */
    public function quiz(): ?array
    {
        $answers = array_filter(
            [
                'natural_hair' => $this->has('natural_hair') ? $this->boolean('natural_hair') : null,
                'metal' => $this->input('metal'),
                'tanning' => $this->input('tanning'),
                'veins' => $this->input('veins'),
            ],
            fn ($value) => $value !== null && $value !== '',
        );

        return $answers === [] ? null : $answers;
    }
}
