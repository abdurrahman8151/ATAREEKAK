<?php

namespace App\Listeners;

use App\Events\RideBooked;

class SendRideBookedNotification
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
    public function handle(RideBooked $event)
    {
        // Handle notifications for driver and passenger
        $ride = $event->ride;
        $booking = $event->booking;

        // Existing notification logic in controller can move here
    }
}
