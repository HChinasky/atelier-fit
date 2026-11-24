<?php

namespace App\Services\Otp;

enum OtpVerifyStatus: string
{
    /** Correct code — consumed exactly once; the caller may log the user in. */
    case Verified = 'verified';

    /** Wrong code (or no active code to check against). */
    case Invalid = 'invalid';

    /** The active code's TTL ran out — the user should request a new one. */
    case Expired = 'expired';

    /** The address is blocked after too many wrong entries. */
    case Blocked = 'blocked';
}
