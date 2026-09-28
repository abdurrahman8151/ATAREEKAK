<?php

namespace App\Listeners;

use App\Events\MessageReceived;

class SendMessageNotification
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
    public function handle(MessageReceived $event): void
    {
        //
    }
}
