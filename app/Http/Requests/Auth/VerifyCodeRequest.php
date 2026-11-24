<?php

namespace App\Http\Requests\Auth;

use App\Services\Otp\EmailAddress;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /auth/email/verify — check the entered code and log in.
 */
class VerifyCodeRequest extends FormRequest
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
            'email' => ['required', 'string', 'max:254', 'email:rfc,strict'],
            'code' => ['required', 'digits:6'],
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
            'code.required' => __('requests.sign_in.code'),
            'code.digits' => __('requests.sign_in.code'),
        ];
    }

    /** The validated address, normalised (trimmed, lower-cased). */
    public function email(): string
    {
        return EmailAddress::normalize((string) $this->validated('email'));
    }
}
