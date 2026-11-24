<?php

namespace App\Services\Otp;

/**
 * The shape of a sign-in e-mail address as the app keys things by it.
 *
 * One address, one spelling: the user table, the code table and the
 * welcome-grant hash all see the normalised form, so "Jane@Example.com" and
 * "jane@example.com" are one account and one grant. Lower-casing the local
 * part is technically lossy (RFC 5321 lets a mailbox be case-sensitive) and
 * deliberately so — no mail provider anyone signs in with treats them apart,
 * and a case-split account is a worse failure than a theoretical one.
 */
final class EmailAddress
{
    public static function normalize(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /**
     * Is this one of the demo/QA addresses that take the fixed test code and
     * never get a message? Fails closed: without a test code of exactly
     * code_length digits the list opens nothing.
     */
    public static function isTestAddress(string $email): bool
    {
        $code = (string) config('otp.test_code');

        if (! preg_match('/^\d{'.(int) config('otp.code_length').'}$/', $code)) {
            return false;
        }

        $email = self::normalize($email);

        foreach ((array) config('otp.test_addresses') as $allowed) {
            if (self::normalize((string) $allowed) === $email) {
                return true;
            }
        }

        return false;
    }
}
