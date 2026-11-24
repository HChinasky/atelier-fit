<?php

namespace App\Http\Requests\Auth;

use App\Services\Otp\EmailAddress;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /auth/email/send — request a one-time code. The Turnstile token is
 * validated separately in the controller because its check is an outbound
 * HTTP call, not input validation.
 */
class SendCodeRequest extends FormRequest
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
            // Strict RFC syntax; no DNS lookup — that would be a network call
            // inside validation, and a typo'd domain simply never gets a code.
            'email' => ['required', 'string', 'max:254', 'email:rfc,strict'],
            // The client-generated browser fingerprint; with the IP it keys
            // the per-requester OTP send limits.
            'fingerprint' => ['required', 'string', 'max:128'],
            'turnstile_token' => ['nullable', 'string', 'max:4096'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $invalid = __('login.email.invalid');

        return [
            'email.required' => $invalid,
            'email.string' => $invalid,
            'email.max' => $invalid,
            'email.email' => $invalid,
        ];
    }

    /** The validated address, normalised (trimmed, lower-cased). */
    public function email(): string
    {
        return EmailAddress::normalize((string) $this->validated('email'));
    }
}
