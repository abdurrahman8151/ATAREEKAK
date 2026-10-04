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

    /*
     * Decision un3 (owner choice (a), 2026-10-03): the EXTERNAL capital account.
     *
     * Money that ENTERS or LEAVES the platform from outside it - an admin wallet credit funded by a
     * real cash deposit, a withdrawal paid out to a bank account - has no internal counterparty. Before
     * this, those movements simply had no ledger legs, which meant "the ledger explains the money"
     * had a permanent hole in it and reconciliation could never be a complete statement.
     *
     * Modelling it as a third SYSTEM wallet is what makes the ledger closed rather than merely
     * mostly-right: an injection DEBITS this wallet (money leaves the outside account and enters the
     * user wallet), and a withdrawal CREDITS it. Every transfer then has both halves, and
     * "sum of all wallet balances equals the sum of the external account's ledger legs" is a
     * statement that can be checked rather than asserted.
     *
     * It is NOT the Primary Admin wallet. The platform's earnings are revenue; this account is the
     * boundary with the world, and conflating them would make the revenue figure wrong.
     */
    'external' => [
        'phone' => env('EXTERNAL_WALLET_PHONE', '0900000042'),
        'wallet_prefix' => 'EXT',
    ],
];
