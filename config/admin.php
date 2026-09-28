<?php

// config/admin.php — wallet routing ONLY.
// Credentials (email, password) have been removed — they now live in the
// employees table, seeded at deployment via SpecialAccountSeeder.
// These phone numbers are the only thing wallet services need to find
// the correct wallet row when processing transactions.

return [
    'system_admin' => [
        'phone' => env('ADMIN_WALLET_PHONE', '0912345678'),
        'wallet_prefix' => 'ADM',
    ],

    'sycash' => [
        'phone' => env('SYCASH_WALLET_PHONE', '0987654321'),
        'wallet_prefix' => 'SYC',
    ],
];
