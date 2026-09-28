<?php

namespace App\Listeners;

use App\Events\RideCancelled;

class SendRideCancelledNotification
{
    /**
     * Create the event listener.
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     */
    public function handle(RideCancelled $event): void
    {
        //
    }
}
