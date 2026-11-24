<?php

namespace App\Services\Otp;

enum OtpSendStatus: string
{
    /** Code stored and the message handed to the mailer. */
    case Sent = 'sent';

    /** A per-requester or per-address send limit refused this attempt. */
    case RateLimited = 'rate_limited';

    /** The address is blocked (too many wrong code entries). */
    case Blocked = 'blocked';

    /** The app-wide send fuse tripped — code sending is temporarily off. */
    case Unavailable = 'unavailable';

    /** The mailer refused or failed to take the message. */
    case Failed = 'failed';
}
