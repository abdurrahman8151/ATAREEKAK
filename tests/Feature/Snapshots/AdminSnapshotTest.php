<?php

namespace Tests\Feature\Snapshots;

/**
 * Batch J - the admin panel.
 *
 * Every read the admin dashboard has: the KPI blocks, the two daily/weekly analytics aggregates,
 * the driver and trip reports, the passenger 360 view, the wallet surface (balances, one wallet's
 * transactions, the charge door), the wallet-request approval queue, the ban/unban doors, the
 * verification queue and the report endpoint.
 *
 * `GET /api/admin/export/pdf` is PARKED: it streams a generated PDF (`Response` with binary
 * content), so there is no JSON body to snapshot - see RouteInventoryTest.
 */
final class AdminSnapshotTest extends ApiSnapshotTestCase
{
    public static function cases(): array
    {
        return [
            'admin/dashboard' => [
                'route' => 'GET /api/admin/dashboard',
                'method' => 'GET',
                'uri' => '/api/admin/dashboard',
                'actor' => 'admin',
                'with_booking' => true,
                'expect' => 200,
            ],
            'admin/dashboard-cities' => [
                'route' => 'GET /api/admin/dashboard/cities',
                'method' => 'GET',
                'uri' => '/api/admin/dashboard/cities',
                'actor' => 'admin',
                'expect' => 200,
            ],
            'admin/dashboard-growth' => [
                'route' => 'GET /api/admin/dashboard/growth',
                'method' => 'GET',
                'uri' => '/api/admin/dashboard/growth',
                'actor' => 'admin',
                'expect' => 200,
            ],
            'admin/dashboard-recent' => [
                'route' => 'GET /api/admin/dashboard/recent',
                'method' => 'GET',
                'uri' => '/api/admin/dashboard/recent',
                'actor' => 'admin',
                'expect' => 200,
            ],
            'admin/dashboard-stats' => [
                'route' => 'GET /api/admin/dashboard/stats',
                'method' => 'GET',
                'uri' => '/api/admin/dashboard/stats',
                'actor' => 'admin',
                'expect' => 200,
            ],
            'admin/drivers' => [
                'route' => 'GET /api/admin/drivers',
                'method' => 'GET',
                'uri' => '/api/admin/drivers',
                'actor' => 'admin',
                'expect' => 200,
            ],
            'admin/drivers-activity' => [
                'route' => 'GET /api/admin/drivers/activity',
                'method' => 'GET',
                'uri' => '/api/admin/drivers/activity',
                'actor' => 'admin',
                'expect' => 200,
            ],
            'admin/drivers-dashboard' => [
                'route' => 'GET /api/admin/drivers/dashboard',
                'method' => 'GET',
                'uri' => '/api/admin/drivers/dashboard',
                'actor' => 'admin',
                'expect' => 200,
            ],
            'admin/drivers-stats' => [
                'route' => 'GET /api/admin/drivers/stats',
                'method' => 'GET',
                'uri' => '/api/admin/drivers/stats',
                'actor' => 'admin',
                'expect' => 200,
            ],
            'admin/drivers-top' => [
                'route' => 'GET /api/admin/drivers/top',
                'method' => 'GET',
                'uri' => '/api/admin/drivers/top',
                'actor' => 'admin',
                'expect' => 200,
            ],
            'admin/drivers-verification-efficiency' => [
                'route' => 'GET /api/admin/drivers/verification-efficiency',
                'method' => 'GET',
                'uri' => '/api/admin/drivers/verification-efficiency',
                'actor' => 'admin',
                'expect' => 200,
            ],
            'admin/driver-dashboard' => [
                'route' => 'GET /api/admin/drivers/{driverId}/dashboard',
                'method' => 'GET',
                'uri' => '/api/admin/drivers/{driver}/dashboard',
                'actor' => 'admin',
                'expect' => 200,
            ],
            'admin/driver-profile' => [
                'route' => 'GET /api/admin/drivers/{driverId}/profile',
                'method' => 'GET',
                'uri' => '/api/admin/drivers/{driver}/profile',
                'actor' => 'admin',
                'expect' => 200,
            ],
            'admin/logout' => [
                'route' => 'POST /api/admin/logout',
                'method' => 'POST',
                'uri' => '/api/admin/logout',
                'actor' => 'admin',
                'expect' => 200,
            ],
            'admin/passenger-charge-wallet' => [
                'route' => 'POST /api/admin/passengers/{userId}/charge-wallet',
                'method' => 'POST',
                'uri' => '/api/admin/passengers/{passenger}/charge-wallet',
                'actor' => 'admin',
                'body' => [
                    'amount' => 5_000,
                    'admin_notes' => 'Snapshot top-up',
                ],
                'expect' => 200,
            ],
            'admin/passenger-complaints' => [
                'route' => 'GET /api/admin/passengers/{userId}/complaints',
                'method' => 'GET',
                'uri' => '/api/admin/passengers/{passenger}/complaints',
                'actor' => 'admin',
                'prepare' => static fn (ApiSnapshotTestCase $test) => $test->createComplaint(),
                'expect' => 200,
            ],
            'admin/passenger-full-profile' => [
                'route' => 'GET /api/admin/passengers/{userId}/full-profile',
                'method' => 'GET',
                'uri' => '/api/admin/passengers/{passenger}/full-profile',
                'actor' => 'admin',
                'expect' => 200,
            ],
            'admin/passenger-monthly-trips' => [
                'route' => 'GET /api/admin/passengers/{userId}/monthly-trips',
                'method' => 'GET',
                'uri' => '/api/admin/passengers/{passenger}/monthly-trips',
                'actor' => 'admin',
                'expect' => 200,
            ],
            'admin/passenger-recent-trips' => [
                'route' => 'GET /api/admin/passengers/{userId}/recent-trips',
                'method' => 'GET',
                'uri' => '/api/admin/passengers/{passenger}/recent-trips',
                'actor' => 'admin',
                'with_booking' => true,
                'expect' => 200,
            ],
            'admin/passenger-stats' => [
                'route' => 'GET /api/admin/passengers/{userId}/stats',
                'method' => 'GET',
                'uri' => '/api/admin/passengers/{passenger}/stats',
                'actor' => 'admin',
                'expect' => 200,
            ],
            'admin/passenger-wallet-charges' => [
                'route' => 'GET /api/admin/passengers/{userId}/wallet-charges',
                'method' => 'GET',
                'uri' => '/api/admin/passengers/{passenger}/wallet-charges',
                'actor' => 'admin',
                'expect' => 200,
            ],
            'admin/reports' => [
                'route' => 'GET /api/admin/reports',
                'method' => 'GET',
                'uri' => '/api/admin/reports',
                'actor' => 'admin',
                'with_booking' => true,
                'expect' => 200,
            ],
            'admin/routes-popular' => [
                'route' => 'GET /api/admin/routes/popular',
                'method' => 'GET',
                'uri' => '/api/admin/routes/popular',
                'actor' => 'admin',
                'expect' => 200,
            ],
            'admin/trips' => [
                'route' => 'GET /api/admin/trips',
                'method' => 'GET',
                'uri' => '/api/admin/trips',
                'actor' => 'admin',
                'expect' => 200,
            ],
            'admin/trips-live' => [
                'route' => 'GET /api/admin/trips/live',
                'method' => 'GET',
                'uri' => '/api/admin/trips/live',
                'actor' => 'admin',
                'expect' => 200,
            ],
            'admin/users' => [
                'route' => 'GET /api/admin/users',
                'method' => 'GET',
                'uri' => '/api/admin/users',
                'actor' => 'admin',
                'expect' => 200,
            ],
            'admin/users-ban' => [
                'route' => 'POST /api/admin/users/{userId}/ban',
                'method' => 'POST',
                'uri' => '/api/admin/users/{other}/ban',
                'actor' => 'admin',
                'body' => [
                    'reason' => 'The snapshot batch bans this account.',
                    'type' => 'permanent',
                ],
                'expect' => 200,
            ],
            'admin/users-status' => [
                'route' => 'GET /api/admin/users/{userId}/status',
                'method' => 'GET',
                'uri' => '/api/admin/users/{other}/status',
                'actor' => 'admin',
                'expect' => 200,
            ],
            'admin/users-unban' => [
                'route' => 'POST /api/admin/users/{userId}/unban',
                'method' => 'POST',
                'uri' => '/api/admin/users/{other}/unban',
                'actor' => 'admin',
                'prepare' => static fn (ApiSnapshotTestCase $test) => $test->banOtherAccount(),
                'body' => ['admin_notes' => 'The snapshot batch lifts the ban.'],
                'expect' => 200,
            ],
            'admin/verifications' => [
                'route' => 'GET /api/admin/verifications',
                'method' => 'GET',
                'uri' => '/api/admin/verifications',
                'actor' => 'admin',
                'prepare' => static fn (ApiSnapshotTestCase $test) => $test->markOtherPendingVerification(),
                'expect' => 200,
            ],
            'admin/verifications-approve' => [
                'route' => 'POST /api/admin/verifications/{userId}/approve',
                'method' => 'POST',
                'uri' => '/api/admin/verifications/{other}/approve',
                'actor' => 'admin',
                'prepare' => static fn (ApiSnapshotTestCase $test) => $test->markOtherPendingVerification(),
                'body' => ['national_id' => '01000000001'],
                'expect' => 200,
            ],
            'admin/verifications-reject' => [
                'route' => 'POST /api/admin/verifications/{userId}/reject',
                'method' => 'POST',
                'uri' => '/api/admin/verifications/{other}/reject',
                'actor' => 'admin',
                'prepare' => static fn (ApiSnapshotTestCase $test) => $test->markOtherPendingVerification(),
                'expect' => 200,
            ],
            'admin/wallet' => [
                'route' => 'GET /api/admin/wallet',
                'method' => 'GET',
                'uri' => '/api/admin/wallet',
                'actor' => 'admin',
                'expect' => 200,
            ],
            'admin/wallet-charge' => [
                'route' => 'POST /api/admin/wallet/charge',
                'method' => 'POST',
                'uri' => '/api/admin/wallet/charge',
                'actor' => 'admin',
                'body' => [
                    'phone_number' => '0900000002',
                    'amount' => 5_000,
                ],
                'expect' => 200,
            ],
            'admin/wallet-requests' => [
                'route' => 'GET /api/admin/wallet/requests',
                'method' => 'GET',
                'uri' => '/api/admin/wallet/requests',
                'actor' => 'admin',
                'prepare' => static fn (ApiSnapshotTestCase $test) => $test->createWalletRequest('charge', 5_000),
                'expect' => 200,
            ],
            'admin/wallet-requests-approve' => [
                'route' => 'POST /api/admin/wallet/requests/{id}/approve',
                'method' => 'POST',
                'uri' => '/api/admin/wallet/requests/{request}/approve',
                'actor' => 'admin',
                'prepare' => static fn (ApiSnapshotTestCase $test) => $test->createWalletRequest('charge', 5_000),
                'expect' => 200,
            ],
            'admin/wallet-requests-reject' => [
                'route' => 'POST /api/admin/wallet/requests/{id}/reject',
                'method' => 'POST',
                'uri' => '/api/admin/wallet/requests/{request}/reject',
                'actor' => 'admin',
                'prepare' => static fn (ApiSnapshotTestCase $test) => $test->createWalletRequest('charge', 5_000),
                'body' => ['admin_notes' => 'The snapshot batch rejects this request.'],
                'expect' => 200,
            ],
            'admin/wallet-transactions' => [
                'route' => 'GET /api/admin/wallet/{walletId}/transactions',
                'method' => 'GET',
                'uri' => '/api/admin/wallet/{wallet}/transactions',
                'actor' => 'admin',
                'with_booking' => true,
                'expect' => 200,
            ],
            'admin/wallets' => [
                'route' => 'GET /api/admin/wallets',
                'method' => 'GET',
                'uri' => '/api/admin/wallets',
                'actor' => 'admin',
                'expect' => 200,
            ],
        ];
    }
}
