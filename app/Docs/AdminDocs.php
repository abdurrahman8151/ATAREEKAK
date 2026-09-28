<?php

namespace App\Docs;

/**
 * ── Admin Auth ────────────────────────────────────────────────────────────────
 *
 * @OA\Post(
 *     path="/api/admin/login",
 *     operationId="adminLogin",
 *     tags={"Admin – Auth"},
 *     summary="Admin login",
 *
 *     @OA\RequestBody(required=true,
 *
 *         @OA\JsonContent(required={"email","password"},
 *
 *             @OA\Property(property="email",    type="string", format="email"),
 *             @OA\Property(property="password", type="string", format="password")
 *         )
 *     ),
 *
 *     @OA\Response(response=200, description="Admin JWT returned"),
 *     @OA\Response(response=401, description="Invalid credentials")
 * )
 *
 * @OA\Post(
 *     path="/api/admin/refresh",
 *     operationId="adminRefresh",
 *     tags={"Admin – Auth"},
 *     summary="Refresh admin JWT",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\Response(response=200, description="New token")
 * )
 *
 * @OA\Post(
 *     path="/api/admin/logout",
 *     operationId="adminLogout",
 *     tags={"Admin – Auth"},
 *     summary="Admin logout",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\Response(response=200, description="Logged out")
 * )
 *
 * @OA\Post(
 *     path="/api/admin/photo",
 *     operationId="adminUploadPhoto",
 *     tags={"Admin – Auth"},
 *     summary="Upload admin profile photo",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\RequestBody(required=true,
 *
 *         @OA\MediaType(mediaType="multipart/form-data",
 *
 *             @OA\Schema(
 *
 *                 @OA\Property(property="photo", type="string", format="binary")
 *             )
 *         )
 *     ),
 *
 *     @OA\Response(response=200, description="Photo uploaded")
 * )
 *
 * ── Admin Dashboard ───────────────────────────────────────────────────────────
 *
 * @OA\Get(
 *     path="/api/admin/dashboard",
 *     operationId="adminDashboard",
 *     tags={"Admin – Dashboard"},
 *     summary="Main admin dashboard",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\Response(response=200, description="Dashboard data")
 * )
 *
 * @OA\Get(
 *     path="/api/admin/dashboard/stats",
 *     operationId="adminDashboardStats",
 *     tags={"Admin – Dashboard"},
 *     summary="Dashboard statistics",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\Response(response=200, description="Stats")
 * )
 *
 * @OA\Get(
 *     path="/api/admin/dashboard/growth",
 *     operationId="adminDashboardGrowth",
 *     tags={"Admin – Dashboard"},
 *     summary="Dashboard growth metrics",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\Response(response=200, description="Growth data")
 * )
 *
 * @OA\Get(
 *     path="/api/admin/dashboard/cities",
 *     operationId="adminDashboardCities",
 *     tags={"Admin – Dashboard"},
 *     summary="City breakdown",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\Response(response=200, description="Cities data")
 * )
 *
 * @OA\Get(
 *     path="/api/admin/dashboard/recent",
 *     operationId="adminDashboardRecent",
 *     tags={"Admin – Dashboard"},
 *     summary="Recent activity",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\Response(response=200, description="Recent activity")
 * )
 *
 * @OA\Get(
 *     path="/api/admin/export/pdf",
 *     operationId="adminExportPdf",
 *     tags={"Admin – Dashboard"},
 *     summary="[system_admin] Export report as PDF",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\Response(response=200, description="PDF file")
 * )
 *
 * @OA\Get(
 *     path="/api/admin/reports",
 *     operationId="adminReports",
 *     tags={"Admin – Dashboard"},
 *     summary="[system_admin] Show system reports",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\Response(response=200, description="Reports data")
 * )
 *
 * ── Admin Users ───────────────────────────────────────────────────────────────
 *
 * @OA\Get(
 *     path="/api/admin/users",
 *     operationId="adminUsers",
 *     tags={"Admin – Users"},
 *     summary="List all users",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\Response(response=200, description="Users list")
 * )
 *
 * @OA\Get(
 *     path="/api/admin/users/{userId}/status",
 *     operationId="adminUserStatus",
 *     tags={"Admin – Users"},
 *     summary="Get user ban status",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\Parameter(name="userId", in="path", required=true, @OA\Schema(type="integer")),
 *
 *     @OA\Response(response=200, description="User status")
 * )
 *
 * @OA\Post(
 *     path="/api/admin/users/{userId}/ban",
 *     operationId="adminUserBan",
 *     tags={"Admin – Users"},
 *     summary="Ban a user",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\Parameter(name="userId", in="path", required=true, @OA\Schema(type="integer")),
 *
 *     @OA\RequestBody(required=false,
 *
 *         @OA\JsonContent(@OA\Property(property="reason", type="string"))
 *     ),
 *
 *     @OA\Response(response=200, description="User banned")
 * )
 *
 * @OA\Post(
 *     path="/api/admin/users/{userId}/unban",
 *     operationId="adminUserUnban",
 *     tags={"Admin – Users"},
 *     summary="Unban a user",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\Parameter(name="userId", in="path", required=true, @OA\Schema(type="integer")),
 *
 *     @OA\Response(response=200, description="User unbanned")
 * )
 *
 * @OA\Get(
 *     path="/api/admin/verifications",
 *     operationId="adminVerifications",
 *     tags={"Admin – Users"},
 *     summary="[system_admin] List pending verifications",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\Response(response=200, description="Verifications list")
 * )
 *
 * @OA\Post(
 *     path="/api/admin/verifications/{userId}/approve",
 *     operationId="adminVerifApprove",
 *     tags={"Admin – Users"},
 *     summary="[system_admin] Approve a verification",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\Parameter(name="userId", in="path", required=true, @OA\Schema(type="integer")),
 *
 *     @OA\Response(response=200, description="Approved")
 * )
 *
 * @OA\Post(
 *     path="/api/admin/verifications/{userId}/reject",
 *     operationId="adminVerifReject",
 *     tags={"Admin – Users"},
 *     summary="[system_admin] Reject a verification",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\Parameter(name="userId", in="path", required=true, @OA\Schema(type="integer")),
 *
 *     @OA\RequestBody(required=false,
 *
 *         @OA\JsonContent(@OA\Property(property="reason", type="string"))
 *     ),
 *
 *     @OA\Response(response=200, description="Rejected")
 * )
 *
 * ── Admin Passenger Profiles ──────────────────────────────────────────────────
 *
 * @OA\Get(
 *     path="/api/admin/passengers/{userId}/full-profile",
 *     operationId="adminPassengerFullProfile",
 *     tags={"Admin – Users"},
 *     summary="Passenger full profile (BFF – all sections in one call)",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\Parameter(name="userId", in="path", required=true, @OA\Schema(type="integer")),
 *
 *     @OA\Response(response=200, description="Full profile data")
 * )
 *
 * @OA\Get(
 *     path="/api/admin/passengers/{userId}/stats",
 *     operationId="adminPassengerStats",
 *     tags={"Admin – Users"},
 *     summary="Passenger stats",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\Parameter(name="userId", in="path", required=true, @OA\Schema(type="integer")),
 *
 *     @OA\Response(response=200, description="Stats")
 * )
 *
 * @OA\Get(
 *     path="/api/admin/passengers/{userId}/monthly-trips",
 *     operationId="adminPassengerMonthlyTrips",
 *     tags={"Admin – Users"},
 *     summary="Passenger monthly trip breakdown",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\Parameter(name="userId", in="path", required=true, @OA\Schema(type="integer")),
 *
 *     @OA\Response(response=200, description="Monthly trips")
 * )
 *
 * @OA\Get(
 *     path="/api/admin/passengers/{userId}/recent-trips",
 *     operationId="adminPassengerRecentTrips",
 *     tags={"Admin – Users"},
 *     summary="Passenger recent trips",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\Parameter(name="userId", in="path", required=true, @OA\Schema(type="integer")),
 *
 *     @OA\Response(response=200, description="Recent trips")
 * )
 *
 * @OA\Get(
 *     path="/api/admin/passengers/{userId}/complaints",
 *     operationId="adminPassengerComplaints",
 *     tags={"Admin – Users"},
 *     summary="Passenger complaints",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\Parameter(name="userId", in="path", required=true, @OA\Schema(type="integer")),
 *
 *     @OA\Response(response=200, description="Complaints list")
 * )
 *
 * @OA\Get(
 *     path="/api/admin/passengers/{userId}/wallet-charges",
 *     operationId="adminPassengerWalletCharges",
 *     tags={"Admin – Users"},
 *     summary="Passenger wallet charge history",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\Parameter(name="userId", in="path", required=true, @OA\Schema(type="integer")),
 *
 *     @OA\Response(response=200, description="Charges list")
 * )
 *
 * @OA\Post(
 *     path="/api/admin/passengers/{userId}/charge-wallet",
 *     operationId="adminPassengerChargeWallet",
 *     tags={"Admin – Users"},
 *     summary="Charge a passenger's wallet directly",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\Parameter(name="userId", in="path", required=true, @OA\Schema(type="integer")),
 *
 *     @OA\RequestBody(required=true,
 *
 *         @OA\JsonContent(required={"amount"},
 *
 *             @OA\Property(property="amount", type="number"),
 *             @OA\Property(property="notes",  type="string")
 *         )
 *     ),
 *
 *     @OA\Response(response=200, description="Wallet charged")
 * )
 *
 * ── Admin Drivers ─────────────────────────────────────────────────────────────
 *
 * @OA\Get(
 *     path="/api/admin/drivers",
 *     operationId="adminDrivers",
 *     tags={"Admin – Drivers"},
 *     summary="List all drivers",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\Response(response=200, description="Drivers list")
 * )
 *
 * @OA\Get(
 *     path="/api/admin/drivers/verification-efficiency",
 *     operationId="adminDriverVerifEfficiency",
 *     tags={"Admin – Drivers"},
 *     summary="Driver verification efficiency stats",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\Response(response=200, description="Stats")
 * )
 *
 * @OA\Get(
 *     path="/api/admin/drivers/dashboard",
 *     operationId="adminDriverDashboard",
 *     tags={"Admin – Drivers"},
 *     summary="Drivers dashboard overview",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\Response(response=200, description="Dashboard data")
 * )
 *
 * @OA\Get(
 *     path="/api/admin/drivers/stats",
 *     operationId="adminDriverStats",
 *     tags={"Admin – Drivers"},
 *     summary="Driver statistics",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\Response(response=200, description="Stats")
 * )
 *
 * @OA\Get(
 *     path="/api/admin/drivers/activity",
 *     operationId="adminDriverActivity",
 *     tags={"Admin – Drivers"},
 *     summary="Driver activity log",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\Response(response=200, description="Activity data")
 * )
 *
 * @OA\Get(
 *     path="/api/admin/drivers/top",
 *     operationId="adminDriversTop",
 *     tags={"Admin – Drivers"},
 *     summary="Top performing drivers",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\Response(response=200, description="Drivers list")
 * )
 *
 * @OA\Get(
 *     path="/api/admin/drivers/{driverId}/profile",
 *     operationId="adminDriverProfile",
 *     tags={"Admin – Drivers"},
 *     summary="Get driver's full profile",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\Parameter(name="driverId", in="path", required=true, @OA\Schema(type="integer")),
 *
 *     @OA\Response(response=200, description="Driver profile")
 * )
 *
 * @OA\Get(
 *     path="/api/admin/drivers/{driverId}/dashboard",
 *     operationId="adminDriverDashboardById",
 *     tags={"Admin – Drivers"},
 *     summary="Driver dashboard by ID",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\Parameter(name="driverId", in="path", required=true, @OA\Schema(type="integer")),
 *
 *     @OA\Response(response=200, description="Driver dashboard")
 * )
 *
 * ── Admin Trips ───────────────────────────────────────────────────────────────
 *
 * @OA\Get(
 *     path="/api/admin/trips",
 *     operationId="adminTrips",
 *     tags={"Admin – Trips"},
 *     summary="List all trips",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\Response(response=200, description="Trips list")
 * )
 *
 * @OA\Get(
 *     path="/api/admin/trips/live",
 *     operationId="adminTripsLive",
 *     tags={"Admin – Trips"},
 *     summary="List live / active trips",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\Response(response=200, description="Live trips")
 * )
 *
 * @OA\Get(
 *     path="/api/admin/routes/popular",
 *     operationId="adminRoutesPopular",
 *     tags={"Admin – Trips"},
 *     summary="Most popular routes",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\Response(response=200, description="Routes list")
 * )
 *
 * ── Admin Wallet ──────────────────────────────────────────────────────────────
 *
 * @OA\Get(
 *     path="/api/admin/wallet",
 *     operationId="adminWallet",
 *     tags={"Admin – Wallet"},
 *     summary="Get admin wallet",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\Response(response=200, description="Admin wallet")
 * )
 *
 * @OA\Get(
 *     path="/api/admin/wallets",
 *     operationId="adminWallets",
 *     tags={"Admin – Wallet"},
 *     summary="List all wallets",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\Response(response=200, description="Wallets list")
 * )
 *
 * @OA\Get(
 *     path="/api/admin/wallet/{walletId}/transactions",
 *     operationId="adminWalletTransactions",
 *     tags={"Admin – Wallet"},
 *     summary="Get transactions for a wallet",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\Parameter(name="walletId", in="path", required=true, @OA\Schema(type="integer")),
 *
 *     @OA\Response(response=200, description="Transactions list")
 * )
 *
 * @OA\Get(
 *     path="/api/admin/wallet/requests",
 *     operationId="adminWalletRequests",
 *     tags={"Admin – Wallet"},
 *     summary="List pending wallet requests",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\Response(response=200, description="Requests list")
 * )
 *
 * @OA\Post(
 *     path="/api/admin/wallet/requests/{id}/approve",
 *     operationId="adminWalletApprove",
 *     tags={"Admin – Wallet"},
 *     summary="Approve a wallet request",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
 *
 *     @OA\Response(response=200, description="Approved")
 * )
 *
 * @OA\Post(
 *     path="/api/admin/wallet/requests/{id}/reject",
 *     operationId="adminWalletReject",
 *     tags={"Admin – Wallet"},
 *     summary="Reject a wallet request",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
 *
 *     @OA\RequestBody(required=false,
 *
 *         @OA\JsonContent(@OA\Property(property="reason", type="string"))
 *     ),
 *
 *     @OA\Response(response=200, description="Rejected")
 * )
 *
 * @OA\Post(
 *     path="/api/admin/wallet/charge",
 *     operationId="adminWalletCharge",
 *     tags={"Admin – Wallet"},
 *     summary="[system_admin] Manually charge a wallet",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\RequestBody(required=true,
 *
 *         @OA\JsonContent(required={"user_id","amount"},
 *
 *             @OA\Property(property="user_id", type="integer"),
 *             @OA\Property(property="amount",  type="number"),
 *             @OA\Property(property="notes",   type="string")
 *         )
 *     ),
 *
 *     @OA\Response(response=200, description="Wallet charged")
 * )
 */
class AdminDocs {}
