<?php

use App\Http\Controllers\API\AdminBanController;
use App\Http\Controllers\API\AdminDashboardController;
use App\Http\Controllers\API\AdminDriverController;
use App\Http\Controllers\API\AdminTripController;
use App\Http\Controllers\API\AdminUserController;
use App\Http\Controllers\API\AdminWalletRequestController;
use App\Http\Controllers\API\ChatController;
use App\Http\Controllers\API\ComplaintController;
use App\Http\Controllers\API\ContactController;
use App\Http\Controllers\API\DocumentController;
use App\Http\Controllers\API\EmailVerificationController;
use App\Http\Controllers\API\ForgotPasswordController;
use App\Http\Controllers\API\LoginController;
use App\Http\Controllers\API\LogoutController;
use App\Http\Controllers\API\NotificationController;
use App\Http\Controllers\API\OtpController;
use App\Http\Controllers\API\PassengerProfileController;
use App\Http\Controllers\API\ProfileController;
use App\Http\Controllers\API\PushNotificationController;
use App\Http\Controllers\API\RefreshTokenController;
use App\Http\Controllers\API\ResetPasswordController;
use App\Http\Controllers\API\RideController;
use App\Http\Controllers\API\ScoreController;
use App\Http\Controllers\API\SignupController;
use App\Http\Controllers\API\Staff\EmployeeManagementController;
use App\Http\Controllers\API\Staff\StaffAdminController;
use App\Http\Controllers\API\Staff\StaffAuthController;
use App\Http\Controllers\API\Staff\StaffChatController;
use App\Http\Controllers\API\Staff\StaffComplaintController;      // ← NEW
use App\Http\Controllers\API\Staff\StaffOperationsController;
use App\Http\Controllers\API\Staff\StaffReviewController;
use App\Http\Controllers\API\TextMeOtpController;
use App\Http\Controllers\API\VerificationController;
use App\Http\Controllers\API\VerifyPasswordOtpController;
use App\Http\Controllers\API\WalletController;
use App\Http\Controllers\API\WalletRequestController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Throttle limiter mapping (defined in RouteServiceProvider):
|
|   throttle:auth    → login, signup, OTP, password reset (strict — 5/min)
|   throttle:api     → all authenticated user endpoints   (60/min)
|   throttle:search  → search, autocomplete, route options (30/min)  ← additive on top of throttle:api
|   throttle:uploads → file/document uploads              (10/min)   ← additive on top of throttle:api
|   throttle:admin   → admin panel + employee management  (300/min)
|   throttle:staff   → staff panel                        (200/min)
|
| "Additive" means both limiters apply independently; the stricter one
| is the effective cap (e.g. search is capped at 30 not 60).
|
*/

// ========================================
// UTILITY — Liveness only. No throttle (must stay reachable during incidents).
//
// These two return fixed constants and expose nothing about the backing
// services, so they are safe to leave unauthenticated. /ping and /test are
// used by the k6 load-test scripts and by RouteServiceProviderTest.
//
// GET /api/test-db was REMOVED here (original-audit T2-6). It was
// unauthenticated and unthrottled and returned the internal database name,
// host, port and table count — and, on failure, the raw driver exception text.
// That is unauthenticated reconnaissance of internal topology. The code's own
// comment said "Remove /test-db before production"; this is that removal.
// ========================================
Route::get('/ping', fn () => response()->json(['ok' => true]));
Route::get('/test', fn () => response()->json([
    'message' => 'API is working!',
    'timestamp' => now(),
]));

// ========================================
// PUBLIC — OTP (WhatsApp / TextMeBot) [throttle:auth]
// ========================================

Route::middleware('throttle:auth')->group(function () {

    Route::prefix('otp')->group(function () {
        Route::post('/send', [OtpController::class, 'sendOtp']);
        Route::post('/verify', [OtpController::class, 'verifyOtp']);
    });

    Route::prefix('textme-otp')->group(function () {
        Route::post('/send', [TextMeOtpController::class, 'sendOtp']);
        Route::post('/verify', [TextMeOtpController::class, 'verifyOtp']);
    });

});

// ========================================
// PUBLIC — AUTHENTICATION [throttle:auth]
// ========================================

Route::middleware('throttle:auth')->prefix('auth')->group(function () {

    Route::post('/signup', [SignupController::class, 'register']);
    Route::post('/login', LoginController::class);
    Route::post('/refresh', RefreshTokenController::class);

    // Password reset (3-step OTP flow)
    Route::post('/password/forgot', ForgotPasswordController::class);
    Route::post('/password/verify-otp', VerifyPasswordOtpController::class);
    Route::post('/password/reset', ResetPasswordController::class);

});

// ========================================
// PUBLIC — EMAIL VERIFICATION [throttle:auth]
// ========================================

Route::middleware('throttle:auth')->prefix('email-verification')->group(function () {
    Route::post('/send', [EmailVerificationController::class, 'send']);
    Route::post('/verify', [EmailVerificationController::class, 'verify']);
    Route::post('/resend', [EmailVerificationController::class, 'resend']);
});

// ========================================
// PROTECTED — JWT AUTH REQUIRED [throttle:api]
// ========================================

Route::middleware(['jwt', 'throttle:api'])->group(function () {

    // Session
    Route::get('/user', fn (Request $r) => response()->json([
        'status' => 'success',
        'user' => $r->user(),
    ]));
    Route::post('/logout', LogoutController::class);

    // RV-27: push-device token management. The controller existed with ZERO routes —
    // no device could register, so FCM delivery was unreachable end-to-end (an app
    // feature that could never work). Token strings are taken from the BODY, never a
    // URL path: a path-embedded FCM token ends up in web-server/proxy access logs.
    // (testNotification stays unrouted — it is a local-only debug helper, listed in
    // RoutesIntegrityTest::UNROUTED_BY_DESIGN rather than exposed permanently.)
    Route::prefix('push-tokens')->group(function () {
        Route::post('/', [PushNotificationController::class, 'registerToken']);
        Route::get('/', [PushNotificationController::class, 'getUserTokens']);
        Route::delete('/', [PushNotificationController::class, 'removeToken']);
    });

    // Score
    Route::prefix('score')->group(function () {
        Route::get('/', [ScoreController::class, 'show']);
        Route::get('/history', [ScoreController::class, 'history']);
        Route::get('/transactions', [ScoreController::class, 'transactions']);
    });

    // Autocomplete [+throttle:search — effective cap: 30/min]
    Route::middleware('throttle:search')
        ->get('/autocomplete', [RideController::class, 'autocomplete']);

    // ── Profile ──────────────────────────────────────────────────────────────

    Route::prefix('profile')->group(function () {

        Route::post('/', [ProfileController::class, 'update']);

        // Document upload [+throttle:uploads — effective cap: 10/min]
        Route::middleware('throttle:uploads')
            ->post('/documents', [DocumentController::class, 'store']);

        Route::prefix('verify')->group(function () {
            Route::post('/passenger', [VerificationController::class, 'verifyPassenger']);
            Route::post('/driver', [VerificationController::class, 'verifyDriver']);
            Route::get('/status/{userId}', [VerificationController::class, 'status']);
        });

        Route::get('/{userId}', [ProfileController::class, 'show']);
        Route::post('/{userId}/comments', [ProfileController::class, 'comment']);
        Route::post('/{userId}/rate', [ProfileController::class, 'rateUser']);

    });

    // ── Rides ─────────────────────────────────────────────────────────────────

    Route::prefix('rides')->group(function () {

        // Search / route calculation [+throttle:search — effective cap: 30/min]
        Route::middleware('throttle:search')->group(function () {
            Route::get('/search', [RideController::class, 'searchRides']);
            Route::post('/search', [RideController::class, 'searchRides']);
            Route::post('/route-options', [RideController::class, 'getRouteOptions']);
        });

        Route::post('/create-with-route', [RideController::class, 'createRideWithRoute']);

        Route::get('/', [RideController::class, 'getRides']);
        // RV-14/V10: this pointed at `createRide`, which does not exist, so the
        // documented create endpoint returned 500 for every caller. The real method
        // is `create`, and it is the validated path (CreateRideRequest); the route
        // previously left that validation unreachable while clients used the weaker
        // inline rules of /create-with-route.
        Route::post('/', [RideController::class, 'create']);

        Route::get('/{rideId}', [RideController::class, 'show']);
        Route::get('/{rideId}/passengers', [RideController::class, 'driverView']);
        Route::patch('/{rideId}/cancel', [RideController::class, 'cancelRide']);
        Route::post('/{rideId}/book', [RideController::class, 'bookRide']);
        Route::post('/{rideId}/finish', [RideController::class, 'finishRide']);
        Route::post('/{rideId}/driver-confirm', [RideController::class, 'driverConfirmCompletion']);
        Route::post('/{rideId}/driver-no-show', [RideController::class, 'reportDriverNoShow']);

    });

    // ── Bookings ──────────────────────────────────────────────────────────────

    Route::prefix('bookings')->group(function () {
        Route::get('/', [RideController::class, 'myBookings']);
        Route::post('/{bookingId}/accept', [RideController::class, 'acceptBooking']);
        Route::post('/{bookingId}/reject', [RideController::class, 'rejectBooking']);
        Route::post('/{bookingId}/cancel', [RideController::class, 'cancelBooking']);
        Route::post('/{bookingId}/cancel-seats', [RideController::class, 'cancelPartialSeats']);
        Route::post('/{bookingId}/passenger-confirm', [RideController::class, 'passengerConfirmCompletion']);
        Route::post('/{bookingId}/passenger-no-show', [RideController::class, 'reportPassengerNoShow']);
    });

    // ── Chat ──────────────────────────────────────────────────────────────────

    Route::prefix('chat')->group(function () {
        Route::get('/conversations', [ChatController::class, 'getConversations']);
        Route::post('/conversations', [ChatController::class, 'startConversation']);
        Route::get('/conversations/{conversationId}/messages', [ChatController::class, 'getMessages']);
        Route::post('/conversations/{conversationId}/messages', [ChatController::class, 'sendMessage']);
        Route::delete('/messages/{messageId}', [ChatController::class, 'deleteMessage']);
    });

    // ── Notifications ─────────────────────────────────────────────────────────

    Route::prefix('notifications')->group(function () {
        Route::get('/', [NotificationController::class, 'index']);
        Route::get('/unread-count', [NotificationController::class, 'getUnreadCount']);
        Route::get('/categories', [NotificationController::class, 'getCategories']);
        Route::post('/read-all', [NotificationController::class, 'markAllAsRead']);
        Route::post('/bulk-action', [NotificationController::class, 'bulkAction']);
        Route::post('/{id}/read', [NotificationController::class, 'markAsRead']);
        Route::post('/{id}/unread', [NotificationController::class, 'markAsUnread']);
        Route::delete('/{id}', [NotificationController::class, 'destroy']);
    });

    // ── Wallet ────────────────────────────────────────────────────────────────

    Route::prefix('wallet')->group(function () {
        Route::get('/balance', [WalletController::class, 'getBalance']);
        Route::post('/initiate', [WalletController::class, 'initiateWalletCreation']);
        Route::post('/verify-and-create', [WalletController::class, 'verifyAndCreateWallet']);
        Route::get('/transactions', [WalletController::class, 'transactions']);
        Route::get('/requests', [WalletRequestController::class, 'myRequests']);
        Route::post('/request-charge', [WalletRequestController::class, 'requestCharge']);
        Route::post('/request-withdraw', [WalletRequestController::class, 'requestWithdraw']);
        Route::post('/requests', [WalletRequestController::class, 'store']);
        Route::get('/requests/{id}', [WalletRequestController::class, 'show']);
        Route::post('/create-direct', [WalletController::class, 'createDirect']);
        Route::delete('/requests/{id}', [WalletRequestController::class, 'destroy']);
    });

    // ── Complaints ────────────────────────────────────────────────────────────

    Route::prefix('complaints')->group(function () {
        Route::get('/', [ComplaintController::class, 'index']);
        Route::post('/', [ComplaintController::class, 'store']);
        Route::get('/{id}', [ComplaintController::class, 'show']);
    });

    // ── Contact (opens support chat) ──────────────────────────────────────────

    Route::post('/contact', ContactController::class);

});

// ========================================
// ADMIN ROUTES
// ========================================

Route::prefix('admin')->group(function () {

    // Public: login + refresh [throttle:auth]
    Route::middleware('throttle:auth')->group(function () {
        Route::post('/login', [AdminDashboardController::class, 'login']);
        Route::post('/refresh', [AdminDashboardController::class, 'refresh']);
    });

    // Everything else: admin + system_admin roles [throttle:admin]
    Route::middleware(['staff:admin,system_admin', 'throttle:admin'])->group(function () {

        // ── Driver routes ──────────────────────────────────────────────────
        Route::get('users', [AdminUserController::class,   'index']);
        Route::get('drivers/verification-efficiency', [AdminDriverController::class, 'verificationEfficiency']);
        Route::get('drivers/dashboard', [AdminDriverController::class, 'dashboard']);
        Route::get('drivers/stats', [AdminDriverController::class, 'stats']);
        Route::get('drivers/activity', [AdminDriverController::class, 'activity']);
        Route::get('drivers', [AdminDriverController::class, 'index']);
        Route::get('drivers/{driverId}/profile', [AdminDriverController::class, 'driverProfile']);
        Route::get('drivers/{driverId}/dashboard', [AdminDriverController::class, 'driverDashboard']);

        // ── Session ────────────────────────────────────────────────────────
        Route::post('/logout', [AdminDashboardController::class, 'logout']);
        // T4-7: POST /admin/photo was removed. Its handler returned
        // {'status':'success','message':'Photo uploaded'} without uploading
        // anything — the employees table has no photo column, so the endpoint
        // could never work. A 404 is the honest answer; a real admin photo
        // upload is a feature (schema + storage), not a no-op claiming success.

        // ── Dashboard ──────────────────────────────────────────────────────
        Route::prefix('dashboard')->group(function () {
            Route::get('/', [AdminDashboardController::class, 'dashboard']);
            Route::get('/stats', [AdminDashboardController::class, 'dashboardStats']);
            Route::get('/growth', [AdminDashboardController::class, 'dashboardGrowth']);
            Route::get('/cities', [AdminDashboardController::class, 'dashboardCities']);
            Route::get('/recent', [AdminDashboardController::class, 'dashboardRecent']);
        });

        // ── Trips ──────────────────────────────────────────────────────────
        Route::prefix('trips')->group(function () {
            Route::get('/live', [AdminTripController::class, 'live']);
            Route::get('/', [AdminTripController::class, 'index']);
        });
        Route::get('/routes/popular', [AdminTripController::class, 'popularRoutes']);
        Route::get('/drivers/top', [AdminTripController::class, 'topDrivers']);

        // ── Wallet [system_admin only] ─────────────────────────────────────
        // These endpoints act on the CALLER'S OWN system wallet, resolved by
        // AdminAuthService::getAdminConfigFromRequest() as
        // config("admin.<role>.phone"). config/admin.php defines that phone only
        // for system_admin and sycash — there is no `admin` entry. The outer
        // gate (staff:admin,system_admin) nevertheless admitted the `admin`
        // role, so getOrCreateWallet() searched phone_number = NULL, found
        // nothing and threw RuntimeException → HTTP 500 on GET /admin/wallet.
        // The whole financial surface is therefore system_admin-only, matching
        // the existing gate on /wallet/charge, /reports and /export/pdf.
        Route::middleware('staff:system_admin')->group(function () {
            Route::prefix('wallet')->group(function () {
                Route::get('/', [AdminDashboardController::class,     'getAdminWallet']);
                Route::get('/{walletId}/transactions', [AdminDashboardController::class,     'showWalletTransactions']);
                Route::get('/requests', [AdminWalletRequestController::class, 'index']);
                Route::post('/requests/{id}/approve', [AdminWalletRequestController::class, 'approve']);
                Route::post('/requests/{id}/reject', [AdminWalletRequestController::class, 'reject']);
            });
            Route::get('/wallets', [AdminDashboardController::class, 'getAdminWallets']);
        });

        // ── UC-ADM-12: Ban / Unban ─────────────────────────────────────────
        Route::prefix('users')->group(function () {
            Route::get('/{userId}/status', [AdminBanController::class, 'userStatus']);
            Route::post('/{userId}/ban', [AdminBanController::class, 'ban']);
            Route::post('/{userId}/unban', [AdminBanController::class, 'unban']);
        });

        // ── Passenger Profile Dashboard ────────────────────────────────────
        Route::prefix('passengers')->group(function () {
            Route::get('/{userId}/full-profile', [PassengerProfileController::class, 'fullProfile']);
            Route::get('/{userId}/stats', [PassengerProfileController::class, 'stats']);
            Route::get('/{userId}/monthly-trips', [PassengerProfileController::class, 'monthlyTrips']);
            Route::get('/{userId}/recent-trips', [PassengerProfileController::class, 'recentTrips']);
            Route::get('/{userId}/complaints', [PassengerProfileController::class, 'complaints']);
            Route::get('/{userId}/wallet-charges', [PassengerProfileController::class, 'walletCharges']);

            // Moves real money into a passenger's wallet and writes a
            // wallet_transactions ledger row, so it sits with the rest of the
            // financial surface (system_admin only) rather than the read-only
            // passenger dashboard above.
            Route::middleware('staff:system_admin')->group(function () {
                Route::post('/{userId}/charge-wallet', [PassengerProfileController::class, 'chargeWallet']);
            });
        });

        // ── System Admin only ──────────────────────────────────────────────
        Route::middleware('staff:system_admin')->group(function () {

            Route::post('/wallet/charge', [AdminDashboardController::class, 'chargeWallet']);
            Route::get('/export/pdf', [AdminDashboardController::class, 'exportPdf']);
            Route::get('/reports', [AdminDashboardController::class, 'showReport']);

            Route::prefix('verifications')->group(function () {
                Route::get('/', [AdminDashboardController::class, 'pendingVerifications']);
                Route::post('/{userId}/approve', [AdminDashboardController::class, 'approveVerification']);
                Route::post('/{userId}/reject', [AdminDashboardController::class, 'rejectVerification']);
            });

        });

    });

});

// ========================================
// STAFF ROUTES
// ========================================

Route::prefix('staff')->name('staff.')->group(function () {

    // Public: login + refresh [throttle:auth]
    Route::middleware('throttle:auth')->group(function () {
        Route::post('login', [StaffAuthController::class, 'login'])->name('login');
        Route::post('refresh', [StaffAuthController::class, 'refresh'])->name('refresh');
    });

    // Protected staff routes [throttle:staff]
    Route::middleware(['staff', 'throttle:staff'])->group(function () {

        Route::post('logout', [StaffAuthController::class, 'logout'])->name('logout');
        Route::get('me', [StaffAuthController::class, 'me'])->name('me');

        Route::prefix('reviews')->name('reviews.')->group(function () {
            Route::get('/', [StaffReviewController::class, 'index'])->name('index');
            Route::delete('/{commentId}', [StaffReviewController::class, 'destroy'])->name('destroy');
        });

        Route::prefix('users')->name('users.')->group(function () {
            Route::get('/', [StaffOperationsController::class, 'users'])->name('index');
            Route::get('/{userId}', [StaffOperationsController::class, 'userProfile'])->name('show');
        });

        Route::get('trips', [StaffOperationsController::class, 'trips'])->name('trips.index');
        Route::get('bookings', [StaffOperationsController::class, 'bookings'])->name('bookings.index');

        Route::prefix('complaints')->name('complaints.')->group(function () {
            Route::get('/', [StaffComplaintController::class, 'index'])->name('index');
            Route::get('/{id}', [StaffComplaintController::class, 'show'])->name('show');
            Route::patch('/{id}/respond', [StaffComplaintController::class, 'respond'])->name('respond');
            Route::patch('/{id}/escalate', [StaffComplaintController::class, 'escalate'])->name('escalate');
        });

        Route::post('trips/{rideId}/cancel', [StaffOperationsController::class, 'cancelTrip'])->name('trips.cancel');
        Route::post('bookings/{bookingId}/cancel', [StaffOperationsController::class, 'cancelBooking'])->name('bookings.cancel');

        // ── Support Chat (support_agent, admin, system_admin) ─────────────
        // All active staff roles can read and reply to support conversations.
        // The agent's User account (matched by email) is the chat participant.
        Route::prefix('chat')->name('chat.')->group(function () {
            Route::get('conversations', [StaffChatController::class, 'conversations'])->name('conversations');
            Route::get('conversations/{id}/messages', [StaffChatController::class, 'messages'])->name('messages');
            Route::post('conversations/{id}/messages', [StaffChatController::class, 'sendMessage'])->name('send');
        });

        // Admin + System Admin only
        Route::middleware('staff:admin,system_admin')->group(function () {

            Route::prefix('verifications')->name('verifications.')->group(function () {
                Route::get('/pending', [StaffAdminController::class, 'pendingVerifications'])->name('pending');
                Route::post('/{userId}/approve', [StaffAdminController::class, 'approveVerification'])->name('approve');
                Route::post('/{userId}/reject', [StaffAdminController::class, 'rejectVerification'])->name('reject');
            });

            Route::prefix('escalated-complaints')->name('escalated-complaints.')->group(function () {
                Route::get('/', [StaffAdminController::class, 'escalatedComplaints'])->name('index');
                Route::patch('/{id}/resolve', [StaffAdminController::class, 'resolveEscalated'])->name('resolve');
            });

        });

    });

});

// ========================================
// EMPLOYEE MANAGEMENT — system_admin only [throttle:admin]
// ========================================

Route::prefix('employees')->middleware(['staff:system_admin', 'throttle:admin'])->group(function () {
    Route::get('/', [EmployeeManagementController::class, 'index']);
    Route::post('/', [EmployeeManagementController::class, 'store']);
    Route::get('/{id}', [EmployeeManagementController::class, 'show']);
    Route::put('/{id}', [EmployeeManagementController::class, 'update']);
    Route::patch('/{id}/toggle-active', [EmployeeManagementController::class, 'toggleActive']);
    Route::patch('/{id}/reset-password', [EmployeeManagementController::class, 'resetPassword']);
});

// GET /api/health was REMOVED here (original-audit T2-6). It was
// unauthenticated and returned gethostname(), which disclosed the container
// identity of whichever of the five app replicas answered and so assisted
// cluster enumeration. The platform healthcheck uses GET /up (render.yaml:5),
// not this route, and nothing in the repository consumed it.
//
// If an internal liveness probe is needed later, put it behind the internal
// network or the admin middleware rather than leaving it public.
