<?php

namespace App\Modules\Communication\Contracts;

use App\Models\User;

/**
 * Stage 1, phase 2 (Communication): the push-notification port.
 *
 * The queued Jobs (`App\Jobs\SendPushNotification`, `App\Jobs\SendPushNotificationJob`) are kernel
 * files - the owner's layout keeps `Jobs` out of the modules because their class names are serialized
 * into the queue - and the kernel may use a module only through its Contracts. They need exactly one
 * call, `sendToUser()`, so that call is the port. `PushNotificationService` already had the method;
 * it now declares that it implements this interface, and the Communication provider binds the port to
 * the same singleton the Jobs used to receive, so construction, instance identity and behaviour are
 * unchanged.
 */
interface PushNotifierInterface
{
    /**
     * Send a push payload to every active device token of a user.
     *
     * @param  array<string, mixed>  $data
     */
    public function sendToUser(User $user, array $data): bool;
}
