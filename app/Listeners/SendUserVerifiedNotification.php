<?php

namespace App\Listeners;

use App\Events\UserVerified;

class SendUserVerifiedNotification
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
    public function handle(UserVerified $event): void
    {
        //
    }
}
