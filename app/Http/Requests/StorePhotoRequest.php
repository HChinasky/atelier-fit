<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a client photo upload before it touches R2. The checks are
 * content-based, not extension-based: `mimetypes` inspects the real MIME via
 * finfo, so a text file renamed to .jpg (or an SVG) is rejected, and the size
 * cap keeps oversized files out of object storage.
 *
 * It also enforces the consent gate. The checkbox in the interface is only half
 * of it — a request that skips the browser entirely must be refused just the
 * same, so consent is REQUIRED here, and required against the wording currently
 * in force (config/consent.php): a consent given for older text no longer
 * authorises processing.
 */
class StorePhotoRequest extends FormRequest
{
    /** The upload cap, in kilobytes (~12 MB). */
    public const MAX_KB = 12288;

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
        $photo = [
            'photo' => [
                'required',
                'file',
                'image',
                // Real MIME from file content — blocks extension spoofing and SVG.
                'mimetypes:image/jpeg,image/png,image/webp',
                // ~12 MB cap (value is in kilobytes).
                'max:'.self::MAX_KB,
            ],
        ];

        // Consent switched off (config/consent.php): the photo checks alone.
        if (! config('consent.required')) {
            return $photo;
        }

        return $photo + [
            // Explicit consent, server-side. "accepted" refuses false/0/absent, so
            // an unticked box cannot pass as agreement.
            'consent_accepted' => ['required', 'accepted'],
            // ...and it must be consent to the CURRENT wording.
            'consent_version' => ['required', 'string', 'in:'.config('consent.version')],
            // Acceptance of the Terms, given by its own box on the same screen.
            // Separate from the consent above: bundling them would make the
            // consent unfree, and an unfree consent is not a consent.
            'terms_accepted' => ['required', 'accepted'],
            'terms_version' => ['required', 'string', 'in:'.config('terms.version')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $type = __('requests.photo.type');

        return [
            'photo.required' => __('requests.photo.required'),
            'photo.uploaded' => __('requests.photo.failed'),
            'photo.file' => $type,
            'photo.image' => $type,
            'photo.mimetypes' => $type,
            'photo.max' => __('requests.photo.max', ['mb' => intdiv(self::MAX_KB, 1024)]),
            'consent_accepted.required' => __('requests.photo.consent'),
            'consent_accepted.accepted' => __('requests.photo.consent'),
            // A page from before the wording changed sends no version, or an old one.
            'consent_version.required' => __('requests.photo.consent_changed'),
            'consent_version.string' => __('requests.photo.consent_changed'),
            'consent_version.in' => __('requests.photo.consent_changed'),
            'terms_accepted.required' => __('requests.photo.terms'),
            'terms_accepted.accepted' => __('requests.photo.terms'),
            'terms_version.required' => __('requests.photo.terms_changed'),
            'terms_version.string' => __('requests.photo.terms_changed'),
            'terms_version.in' => __('requests.photo.terms_changed'),
        ];
    }
}
