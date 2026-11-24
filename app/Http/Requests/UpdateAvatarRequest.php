<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A new profile picture. Content-based checks, as for try-on photos: the real
 * MIME is read from the bytes, so a renamed text file or an SVG is refused.
 */
class UpdateAvatarRequest extends FormRequest
{
    /** The route is behind auth; a member only ever changes their own. */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'avatar' => [
                'required',
                'file',
                'image',
                'mimetypes:image/jpeg,image/png,image/webp',
                'max:'.(int) config('fitting_room.avatar.max_kb', 8192),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'avatar.required' => __('requests.avatar.required'),
            'avatar.uploaded' => __('requests.avatar.unreadable'),
            'avatar.file' => __('requests.avatar.type'),
            'avatar.image' => __('requests.avatar.type'),
            'avatar.mimetypes' => __('requests.avatar.type'),
            'avatar.max' => __('requests.avatar.max', ['mb' => intdiv((int) config('fitting_room.avatar.max_kb', 8192), 1024)]),
        ];
    }
}
