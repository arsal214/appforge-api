<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Queue\SerializesModels;

abstract class AppForgeMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    /**
     * An email about a record that has since been deleted is not an email
     * anyone wants, and `SerializesModels` re-fetches on the way out: without
     * this it throws ModelNotFoundException, burns its three tries and lands
     * in failed_jobs. Clearing test orders was enough to do it.
     */
    public bool $deleteWhenMissingModels = true;

    public int $tries = 3;

    public int $backoff = 60;

    /**
     * Our address under the shop's name, for mail to a shop's own customer:
     * they signed up to Sams Pizza, and have never heard of AppForge.
     */
    protected static function fromShop(string $appName): Address
    {
        return new Address((string) config('mail.from.address'), $appName);
    }
}
