<?php

namespace App\Support;

use Illuminate\Contracts\Foundation\Application;

/**
 * RV-16 — a live OTP code must never be returned to a client outside local/testing.
 *
 * Three separate services could disclose the code, under three different conditions:
 *
 *  - `EmailOtpService`   — whenever `EMAIL_OTP_MODE=testing` was set, with NO check
 *                          of the application environment at all.
 *  - `WhatsAppOtpService`— whenever `WALLET_OTP_MODE=testing` was set.
 *  - `TextMeBotOtpService`— when the provider API key is unset, AND separately when
 *                          sending FAILS. The failure path is the worst of the three:
 *                          any transient SMS/WhatsApp outage publishes every code.
 *
 * An OTP is a single-factor credential: disclosing it lets the holder complete a
 * signup, a password reset or a wallet withdrawal. One misconfigured environment
 * variable therefore turns any of these into account takeover, which is why the
 * guard is not "is the mode enabled" but "is this a development environment".
 *
 * Each service routes its whole send path through {@see self::sanitize()} so the
 * check cannot be bypassed by adding another return statement later.
 */
final class OtpDisclosure
{
    /**
     * Environments in which returning the code to the caller is safe.
     *
     * Deliberately NOT configurable: adding an environment here would silently
     * re-open the leak on that environment.
     */
    private const ALLOWED_ENVIRONMENTS = ['local', 'testing'];

    /**
     * Whether the current environment may be told the OTP code.
     */
    public static function isAllowed(): bool
    {
        /** @var Application $app */
        $app = app();

        return $app->environment(self::ALLOWED_ENVIRONMENTS);
    }

    /**
     * Remove `otp_code` from a service result unless the environment allows it.
     *
     * Non-OTP results pass through untouched, so callers can apply this to every
     * return value of a send method without changing their control flow.
     */
    public static function sanitize(array $result): array
    {
        if (self::isAllowed()) {
            return $result;
        }

        unset($result['otp_code']);

        return $result;
    }
}
