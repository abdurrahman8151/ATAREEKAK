<?php

namespace App\Support;

/**
 * Production boot refusals moved out of AppServiceProvider verbatim: the JWT signing-secret strength check
 * and the OTP testing-mode refusal. AppServiceProvider::boot() still calls both through its own private
 * methods, which delegate here, so the boot order and the refusal messages are unchanged.
 */
class BootGuards
{
    public static function jwtSecret(): void
    {
        if (app()->environment('local', 'testing')) {
            return;
        }

        $secret = (string) config('jwt.secret');

        if (strlen($secret) < 32) {
            throw new \RuntimeException(
                'JWT_SECRET must be set to at least 32 bytes outside local/testing '
                .'environments; it currently resolves to '
                .($secret === '' ? 'an EMPTY value' : strlen($secret).' bytes')
                .'. Tokens signed with an empty or short key are forgeable. '
                .'Generate one with: php artisan jwt:secret'
            );
        }
    }

    public static function guardOtpTestingModes(): void
    {
        if (app()->environment('local', 'testing')) {
            return;
        }

        $dangerous = [];

        foreach ([
            'EMAIL_OTP_MODE' => config('otp.email_mode'),
            'WALLET_OTP_MODE' => config('otp.wallet_mode'),
            'OTP_BYPASS_ENABLED' => config('otp.bypass') ? 'true' : null,
        ] as $name => $value) {
            if (is_string($value) && strtolower(trim($value)) === ($name === 'OTP_BYPASS_ENABLED' ? 'true' : 'testing')) {
                $dangerous[] = $name.'='.$value;
            }
        }

        if ($dangerous !== []) {
            throw new \RuntimeException(
                'Refusing to start with a testing OTP mode enabled outside local/testing: '
                .implode(', ', $dangerous).'. In this state every OTP the app issues is '
                .'readable by whoever requests it (signup, password reset, wallet top-up). '
                .'Remove the variable(s) from the deployment environment.'
            );
        }
    }
}
