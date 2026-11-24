<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the lead-capture payload. The contact is validated ACCORDING TO the
 * channel: an email channel requires a real email, a whatsapp channel requires a
 * plausible phone number. Anything else is a 422 — nothing reaches the database.
 *
 * marketing_opt_in is optional and defaults to no. Giving a contact to use the
 * service and agreeing to be marketed to are two different permissions, and only
 * the first one is a condition of anything.
 */
class StoreLeadRequest extends FormRequest
{
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
        return [
            'channel' => ['required', Rule::in(['whatsapp', 'email'])],
            'contact' => ['required', 'string', $this->contactRule()],
            // Marketing is a SEPARATE, optional permission — never required, never
            // implied by handing over a contact. Absent means "no", which is why it
            // is nullable rather than required-with-a-default.
            'marketing_opt_in' => ['nullable', 'boolean'],
        ];
    }

    /**
     * The channel-specific contact rule.
     *
     * whatsapp → E.164-ish phone (optional +, 8–15 digits, no leading zero).
     * email    → a valid email address.
     * unknown  → a harmless filler; the channel rule above already fails it.
     */
    private function contactRule(): string
    {
        return match ($this->input('channel')) {
            'whatsapp' => 'regex:/^\+?[1-9]\d{7,14}$/',
            'email' => 'email:rfc',
            default => 'string',
        };
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'contact.regex' => 'The contact must be a valid phone number.',
        ];
    }
}
