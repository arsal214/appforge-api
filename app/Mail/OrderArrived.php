<?php

namespace App\Mail;

use App\Models\Order;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class OrderArrived extends AppForgeMail
{
    public function __construct(
        public readonly string $appId,
        public readonly string $appName,
        public readonly Order $order,
    ) {}

    public function envelope(): Envelope
    {
        $what = $this->order->kind;
        $subject = "New {$what} #{$this->order->number} — {$this->appName}";

        return new Envelope(
            subject: $this->order->is_test ? "[Test] {$subject}" : $subject,
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.order-arrived',
            with: [
                'appId' => $this->appId,
                'appName' => $this->appName,
                'order' => $this->order,
                'lines' => $this->order->lines,
                'details' => $this->order->details,
            ],
        );
    }
}
