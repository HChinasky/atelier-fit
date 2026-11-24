<?php

namespace App\Http\Requests;

use App\Models\ColorProfileLabel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * What a stylist is allowed to say about a face.
 *
 * Every vocabulary here is the engine's own, or maps onto one of its axes. A
 * label using words the engine cannot produce would be a row the report cannot
 * compare, which is the only reason the row exists.
 */
class StoreColorProfileLabelRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The route is already behind feature + auth + stylist. Ownership of
        // the label is decided by the controller, which keys on the signed-in
        // user rather than trusting anything in the request.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // The verdict and how sure they are. Required together: an
            // unqualified verdict and a guess weigh the same in a report that
            // cannot tell them apart.
            'season' => ['required', Rule::in(ColorProfileLabel::SEASONS)],
            'stylist_confidence' => ['required', Rule::in(ColorProfileLabel::CONFIDENCES)],

            // A second season for a face on a boundary. Must differ from the
            // first, because "true_autumn or true_autumn" is not a hedge.
            'season_alt' => [
                'nullable',
                Rule::in(ColorProfileLabel::SEASONS),
                'different:season',
            ],

            // The axes, judged separately. All optional: a stylist sure about
            // the undertone and unsure about the season should be able to say
            // exactly that, and forcing a guess into the other boxes would put
            // noise into the very numbers the anchors get set from.
            'undertone' => ['nullable', Rule::in(ColorProfileLabel::UNDERTONES)],
            'depth' => ['nullable', Rule::in(ColorProfileLabel::DEPTHS)],
            'chroma' => ['nullable', Rule::in(ColorProfileLabel::CHROMAS)],

            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * The label as the model wants it, with blanks normalised to null.
     *
     * An empty select posts "" and a nullable column wants null; leaving the
     * difference in would make "not judged" and "judged as empty string" two
     * distinct states in the dataset.
     *
     * @return array<string, mixed>
     */
    public function label(): array
    {
        $fields = [
            'season', 'season_alt', 'undertone', 'depth', 'chroma',
            'stylist_confidence', 'notes',
        ];

        $label = [];
        foreach ($fields as $field) {
            $value = $this->input($field);
            $label[$field] = is_string($value) && trim($value) === '' ? null : $value;
        }

        return $label;
    }
}
