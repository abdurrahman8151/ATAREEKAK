<?php

namespace Tests\Feature\Snapshots;

/**
 * Batch C - the passenger wallet surface.
 *
 * Balance, the two-step wallet creation, the direct-creation door, the transaction history and
 * the charge/withdraw request lifecycle. The money MOVEMENT itself is covered by the ledger
 * property tests; this batch pins the HTTP contract those movements are reported through, because
 * a balance or a request status that changes shape is what a client breaks on.
 */
final class WalletSnapshotTest extends ApiSnapshotTestCase
{
    public static function cases(): array
    {
        return [
            'wallet/balance' => [
                'route' => 'GET /api/wallet/balance',
                'method' => 'GET',
                'uri' => '/api/wallet/balance',
                'actor' => 'passenger',
                'expect' => 200,
            ],
            'wallet/initiate' => [
                'route' => 'POST /api/wallet/initiate',
                'method' => 'POST',
                'uri' => '/api/wallet/initiate',
                'actor' => 'walletless',
                'body' => [
                    'phone_number' => '0900000004',
                    'password' => 'snapshot-password',
                ],
                'expect' => 200,
            ],
            'wallet/verify-and-create-missing-session' => [
                'route' => 'POST /api/wallet/verify-and-create',
                'method' => 'POST',
                'uri' => '/api/wallet/verify-and-create',
                'actor' => 'walletless',
                'body' => ['otp_code' => '000000'],
                'expect' => 400,
            ],
            'wallet/create-direct' => [
                'route' => 'POST /api/wallet/create-direct',
                'method' => 'POST',
                'uri' => '/api/wallet/create-direct',
                'actor' => 'walletless',
                'body' => ['phone_number' => '0900000004'],
                'expect' => 201,
            ],
            'wallet/transactions' => [
                'route' => 'GET /api/wallet/transactions',
                'method' => 'GET',
                'uri' => '/api/wallet/transactions',
                'actor' => 'passenger',
                'with_booking' => true,
                'expect' => 200,
            ],
            'wallet/requests-index' => [
                'route' => 'GET /api/wallet/requests',
                'method' => 'GET',
                'uri' => '/api/wallet/requests',
                'actor' => 'passenger',
                'expect' => 200,
            ],
            'wallet/request-charge' => [
                'route' => 'POST /api/wallet/request-charge',
                'method' => 'POST',
                'uri' => '/api/wallet/request-charge',
                'actor' => 'passenger',
                'body' => [
                    'amount' => 5_000,
                    'notes' => 'Snapshot top-up',
                ],
                'expect' => 201,
            ],
            'wallet/request-withdraw' => [
                'route' => 'POST /api/wallet/request-withdraw',
                'method' => 'POST',
                'uri' => '/api/wallet/request-withdraw',
                'actor' => 'passenger',
                'body' => [
                    'amount' => 1_000,
                    'notes' => 'Snapshot withdrawal',
                ],
                'expect' => 201,
            ],
            'wallet/requests-store' => [
                'route' => 'POST /api/wallet/requests',
                'method' => 'POST',
                'uri' => '/api/wallet/requests',
                'actor' => 'passenger',
                'body' => [
                    'type' => 'charge',
                    'amount' => 2_000,
                    'notes' => 'Snapshot generic request',
                ],
                'expect' => 201,
            ],
            'wallet/requests-show' => [
                'route' => 'GET /api/wallet/requests/{id}',
                'method' => 'GET',
                'uri' => '/api/wallet/requests/{request}',
                'actor' => 'passenger',
                'prepare' => static function (ApiSnapshotTestCase $test): void {
                    $test->caseVars['{request}'] = $test->createWalletRequest('charge', 3_000);
                },
                'expect' => 200,
            ],
            'wallet/requests-destroy' => [
                'route' => 'DELETE /api/wallet/requests/{id}',
                'method' => 'DELETE',
                'uri' => '/api/wallet/requests/{request}',
                'actor' => 'passenger',
                'prepare' => static function (ApiSnapshotTestCase $test): void {
                    $test->caseVars['{request}'] = $test->createWalletRequest('charge', 3_000);
                },
                'expect' => 200,
            ],
        ];
    }
}
