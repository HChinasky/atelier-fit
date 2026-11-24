<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The one-time sign-in code, as the message that carries it. The code is the
 * whole point of the mail, so it is in the subject line too — most people
 * read it from the inbox preview without opening anything.
 */
class LoginCode extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $code,
        public readonly int $ttlMinutes,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('mail.login_code.subject', ['code' => $this->code]),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.login-code',
            text: 'mail.login-code-text',
        );
    }
}
