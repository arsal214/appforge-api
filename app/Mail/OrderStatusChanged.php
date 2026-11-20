<?php

namespace App\Mail;

use App\Models\Order;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Tells a shop's signed-in customer that the shop has moved their order on. */
class OrderStatusChanged extends AppForgeMail
{
    /**
     * Which moves a customer hears about, in the words they read. A message
     * being "replied" is told by the reply itself, and a booking marked
     * complete after the appointment tells them nothing they do not know.
     */
    private const NEWS = [
        'order' => [
            'confirmed' => 'is confirmed',
            'completed' => 'is complete',
            'cancelled' => 'was cancelled',
        ],
        'booking' => [
            'confirmed' => 'is confirmed',
            'cancelled' => 'was cancelled',
        ],
    ];

    /**
     * `$status` is the one set at the moment of the change, not read off the
     * order when the queue gets round to it: confirmed then cancelled a
     * minute later is two emails that each say what happened.
     */
    public function __construct(
        public readonly string $appName,
        public readonly Order $order,
        public readonly string $status,
    ) {}

    public static function tells(string $kind, string $status): bool
    {
        return isset(self::NEWS[$kind][$status]);
    }

    private function headline(): string
    {
        return "Your {$this->order->kind} #{$this->order->number} "
            .(self::NEWS[$this->order->kind][$this->status] ?? 'has changed');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: self::fromShop($this->appName),
            subject: "{$this->headline()} — {$this->appName}",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.order-status-changed',
            with: [
                // Not `appName`: the public property of that name is passed
                // too, unescaped, and would win.
                'shop' => OrderArrived::plain($this->appName),
                'headline' => $this->headline(),
                'cancelled' => $this->status === 'cancelled',
                'lines' => $this->order->lines ?? [],
                'total' => $this->order->total,
                'plain' => OrderArrived::plain(...),
            ],
        );
    }
}
