<?php

namespace App\Mail;

use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** The code a shop's customer types into the app to set a new password. */
class AppPasswordCode extends AppForgeMail
{
    // One try: a code that arrives an hour late has already run out.
    public int $tries = 1;

    public function __construct(
        public readonly string $appName,
        public readonly string $code,
        public readonly int $minutes,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: self::fromShop($this->appName),
            subject: "Your code for {$this->appName}: {$this->code}",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.app-password-code',
            with: [
                // Not `appName`: a public property of that name would be passed
                // as well, unescaped, and win.
                'shop' => OrderArrived::plain($this->appName),
                'code' => $this->code,
                'minutes' => $this->minutes,
            ],
        );
    }
}
