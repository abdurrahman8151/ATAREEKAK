<?php

namespace Tests\Feature\Snapshots;

/**
 * Batch A - the authentication surface.
 *
 * Every public door into the product: the user login/refresh pair, signup, the three-step
 * password reset, the two e-mail-verification doors, the two OTP providers, and the two staff
 * doors. These are the routes an unauthenticated caller can reach, so their exact response shape
 * is the most security-relevant thing in the suite: an enumeration oracle or a leaked internal
 * message shows up here as a changed body.
 *
 * Both the success and the refusal of each pair are pinned. A snapshot of the success alone
 * cannot catch a refusal that starts leaking.
 */
final class AuthSnapshotTest extends ApiSnapshotTestCase
{
    public static function cases(): array
    {
        return [
            'auth/login-success' => [
                'route' => 'POST /api/auth/login',
                'method' => 'POST',
                'uri' => '/api/auth/login',
                'body' => [
                    'email' => 'snapshot.passenger@example.test',
                    'password' => 'snapshot-password',
                ],
                'expect' => 200,
            ],
            'auth/login-invalid-credentials' => [
                'route' => 'POST /api/auth/login',
                'method' => 'POST',
                'uri' => '/api/auth/login',
                'body' => [
                    'email' => 'snapshot.passenger@example.test',
                    'password' => 'wrong-password',
                ],
                'expect' => 401,
            ],
            'auth/refresh-success' => [
                'route' => 'POST /api/auth/refresh',
                'method' => 'POST',
                'uri' => '/api/auth/refresh',
                'prepare' => static function (ApiSnapshotTestCase $test): void {
                    $test->caseVars['{refresh}'] = $test->loginForRefreshToken('snapshot.passenger@example.test');
                },
                'body' => ['refresh_token' => '{refresh}'],
                'expect' => 200,
            ],
            'auth/refresh-invalid' => [
                'route' => 'POST /api/auth/refresh',
                'method' => 'POST',
                'uri' => '/api/auth/refresh',
                'body' => ['refresh_token' => 'not-a-real-refresh-token'],
                'expect' => 401,
            ],
            'auth/signup-success' => [
                'route' => 'POST /api/auth/signup',
                'method' => 'POST',
                'uri' => '/api/auth/signup',
                'body' => [
                    'first_name' => 'Snapshot',
                    'last_name' => 'Signup',
                    'email' => 'snapshot.signup@example.test',
                    'password' => 'snapshot-password',
                    'password_confirmation' => 'snapshot-password',
                    'gender' => 'M',
                    'address' => 'دمشق',
                ],
                'expect' => 201,
            ],
            'auth/password-forgot' => [
                'route' => 'POST /api/auth/password/forgot',
                'method' => 'POST',
                'uri' => '/api/auth/password/forgot',
                'body' => ['email' => 'snapshot.passenger@example.test'],
                'expect' => 200,
            ],
            'auth/password-verify-otp-invalid' => [
                'route' => 'POST /api/auth/password/verify-otp',
                'method' => 'POST',
                'uri' => '/api/auth/password/verify-otp',
                'body' => [
                    'email' => 'snapshot.passenger@example.test',
                    'otp_code' => '000000',
                ],
                'expect' => 400,
            ],
            'auth/password-reset-expired-token' => [
                'route' => 'POST /api/auth/password/reset',
                'method' => 'POST',
                'uri' => '/api/auth/password/reset',
                'body' => [
                    'reset_token' => '00000000-0000-4000-8000-000000000000',
                    'password' => 'snapshot-password',
                    'password_confirmation' => 'snapshot-password',
                ],
                'expect' => 400,
            ],
            'auth/email-verification-send' => [
                'route' => 'POST /api/email-verification/send',
                'method' => 'POST',
                'uri' => '/api/email-verification/send',
                'prepare' => static fn (ApiSnapshotTestCase $test) => $test->clearOtpRows('snapshot.other@example.test'),
                'body' => ['email' => 'snapshot.other@example.test'],
                'expect' => 200,
            ],
            'auth/email-verification-verify-invalid' => [
                'route' => 'POST /api/email-verification/verify',
                'method' => 'POST',
                'uri' => '/api/email-verification/verify',
                'prepare' => static fn (ApiSnapshotTestCase $test) => $test->clearOtpRows('snapshot.other@example.test'),
                'body' => [
                    'email' => 'snapshot.other@example.test',
                    'otp_code' => '000000',
                ],
                'expect' => 400,
            ],
            'auth/email-verification-resend' => [
                'route' => 'POST /api/email-verification/resend',
                'method' => 'POST',
                'uri' => '/api/email-verification/resend',
                'body' => ['email' => 'snapshot.other@example.test'],
                'expect' => 200,
            ],
            'auth/otp-send' => [
                'route' => 'POST /api/otp/send',
                'method' => 'POST',
                'uri' => '/api/otp/send',
                'prepare' => static fn (ApiSnapshotTestCase $test) => $test->clearOtpRows('0912345678'),
                'body' => [
                    'phone_number' => '0912345678',
                    'type' => 'login',
                ],
                'expect' => 200,
            ],
            'auth/otp-verify-invalid' => [
                'route' => 'POST /api/otp/verify',
                'method' => 'POST',
                'uri' => '/api/otp/verify',
                'prepare' => static fn (ApiSnapshotTestCase $test) => $test->clearOtpRows('0912345678'),
                'body' => [
                    'phone_number' => '0912345678',
                    'otp_code' => '000000',
                ],
                'expect' => 400,
            ],
            'auth/textme-otp-send-disabled' => [
                'route' => 'POST /api/textme-otp/send',
                'method' => 'POST',
                'uri' => '/api/textme-otp/send',
                'body' => ['phone_number' => '0912345678'],
                'expect' => 400,
            ],
            'auth/textme-otp-verify-invalid' => [
                'route' => 'POST /api/textme-otp/verify',
                'method' => 'POST',
                'uri' => '/api/textme-otp/verify',
                'prepare' => static fn (ApiSnapshotTestCase $test) => $test->clearOtpRows('0912345678'),
                'body' => [
                    'phone_number' => '0912345678',
                    'otp_code' => '000000',
                ],
                'expect' => 400,
            ],
            'auth/admin-login-success' => [
                'route' => 'POST /api/admin/login',
                'method' => 'POST',
                'uri' => '/api/admin/login',
                'prepare' => static fn (ApiSnapshotTestCase $test) => $test->adminEmployee(),
                'body' => [
                    'username' => 'snapshot_admin',
                    'password' => 'snapshot-password',
                ],
                'expect' => 200,
            ],
            'auth/admin-login-invalid' => [
                'route' => 'POST /api/admin/login',
                'method' => 'POST',
                'uri' => '/api/admin/login',
                'prepare' => static fn (ApiSnapshotTestCase $test) => $test->adminEmployee(),
                'body' => [
                    'username' => 'snapshot_admin',
                    'password' => 'wrong-password',
                ],
                'expect' => 401,
            ],
            'auth/admin-refresh-invalid' => [
                'route' => 'POST /api/admin/refresh',
                'method' => 'POST',
                'uri' => '/api/admin/refresh',
                'body' => ['refresh_token' => 'not-a-real-refresh-token'],
                'expect' => 401,
            ],
            'auth/staff-login-success' => [
                'route' => 'POST /api/staff/login',
                'method' => 'POST',
                'uri' => '/api/staff/login',
                'prepare' => static fn (ApiSnapshotTestCase $test) => $test->staffEmployee(),
                'body' => [
                    'identifier' => 'snapshot_staff',
                    'password' => 'snapshot-password',
                ],
                'expect' => 200,
            ],
            'auth/staff-login-invalid' => [
                'route' => 'POST /api/staff/login',
                'method' => 'POST',
                'uri' => '/api/staff/login',
                'prepare' => static fn (ApiSnapshotTestCase $test) => $test->staffEmployee(),
                'body' => [
                    'identifier' => 'snapshot_staff',
                    'password' => 'wrong-password',
                ],
                'expect' => 401,
            ],
            'auth/staff-refresh-invalid' => [
                'route' => 'POST /api/staff/refresh',
                'method' => 'POST',
                'uri' => '/api/staff/refresh',
                'body' => ['refresh_token' => 'not-a-real-refresh-token'],
                'expect' => 401,
            ],
        ];
    }
}
