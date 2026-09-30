<?php

/**
 * RV-37 — the OTP modes, as configuration rather than raw process environment.
 *
 * WHY THIS FILE EXISTS
 *
 * These three values were read straight from the environment by the OTP services and
 * the boot guard. Because `putenv()` writes to the whole PHP process and tests run
 * in one process, a single test that set `EMAIL_OTP_MODE=testing` leaked that value
 * into every test that ran after it. That was measured, not theorised: V14 found the
 * suite reporting 53 failures in default order and 55 under `--order-by=random`, and
 * V13 found seven files calling `putenv()`.
 *
 * Reading them through config fixes the class of defect rather than one instance:
 * `config()` is rebuilt per test by the framework, so a test can override a value
 * for itself and cannot leak it into a neighbour, and there is no ambient process
 * state left to leak.
 *
 * The environment variables still work — `env()` here reads them exactly as before —
 * so deployments need no change. What changes is that nothing reads them directly any
 * more.
 */
return [

    /*
    |----------------------------------------------------------------------
    | Email OTP mode
    |----------------------------------------------------------------------
    |
    | "testing" makes EmailOtpService return the code in its result. That is only
    | ever safe locally: App\Support\OtpDisclosure strips it outside local/testing,
    | and guardOtpTestingModes() refuses to boot with it set outside those.
    |
    */

    'email_mode' => env('EMAIL_OTP_MODE', 'production'),

    /*
    |----------------------------------------------------------------------
    | Wallet OTP mode
    |----------------------------------------------------------------------
    |
    | Same contract as email_mode, for the wallet top-up / charge OTP flow.
    |
    */

    'wallet_mode' => env('WALLET_OTP_MODE', 'production'),

    /*
    |----------------------------------------------------------------------
    | OTP bypass
    |----------------------------------------------------------------------
    |
    | Skips OTP verification entirely. Never enable this outside local/testing:
    | it removes the only verification step on wallet operations.
    |
    */

    'bypass' => filter_var(env('OTP_BYPASS_ENABLED', false), FILTER_VALIDATE_BOOL),

];
