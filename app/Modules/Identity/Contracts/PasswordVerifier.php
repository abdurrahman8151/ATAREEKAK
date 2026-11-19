<?php

namespace App\Modules\Identity\Contracts;

/**
 * Stage 3 A4: verify a user's account password WITHOUT the hash ever leaving Identity.
 *
 * Before this port, Money's wallet provisioning read the hash itself
 * (`WalletProvisioningService::passwordHashFor()`, a `users.password` select on the write PDO) and ran
 * `Hash::check()` in a Money controller. That put an Identity column - the password hash, of all things - in
 * another module's hands for the length of one comparison. The hash is Identity's most sensitive column; the only
 * thing a caller needs from it is the ANSWER to "does this password match".
 *
 * The read still goes to the primary (`useWritePdo()`): a password check must never be served by a lagging
 * replica, which is the same reason the old read used it. One query, unchanged.
 */
interface PasswordVerifier
{
    /**
     * True when `$plain` is the user's current password. False when it is not, and false when the user does not
     * exist - a caller cannot distinguish "no such user" from "wrong password", and should not.
     */
    public function verify(int $userId, string $plain): bool;
}
