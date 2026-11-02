<?php

namespace App\Modules\Money\Http\Controllers;

use App\Domain\ValueObjects\Email;
use App\DTOs\Auth\SendEmailOtpDTO;
use App\DTOs\Auth\VerifyEmailOtpDTO;
use App\Http\Controllers\Controller;
use App\Http\Resources\WalletTransactionResource;
use App\Modules\Identity\Contracts\EmailOtpServiceInterface;
use App\Modules\Money\Services\WalletProvisioningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class WalletController extends Controller
{
    public function __construct(
        private readonly EmailOtpServiceInterface $emailOtpService,
        private readonly WalletProvisioningService $walletProvisioning,
    ) {}

    // ─────────────────────────────────────────────────────────────────────────
    // STEP 1 – Initiate wallet creation
    // POST /api/wallet/initiate
    //
    // Validates the phone number, stores it in cache, then sends a 6-digit
    // OTP to the authenticated user's email address (same flow as signup).
    // Password IS required: a valid JWT alone is not enough to start wallet creation.
    // ─────────────────────────────────────────────────────────────────────────
    public function initiateWalletCreation(Request $request): JsonResponse
    {
        // the authenticated user, hydrated once by the JWT middleware
        $user = $request->user();
        // RV-37 / un9: the authenticated user comes from JwtAuthMiddleware's cached
        // hydration, which does not eager-load relations. Every $user->wallet read below
        // would be a lazy load (one query each) - load it once, explicitly.
        $user->loadMissing('wallet');

        if ($user->wallet) {
            return $this->walletAlreadyExistsResponse();
        }

        $validationError = $this->validateInitiateRequest($request);
        if ($validationError) {
            return $validationError;
        }

        $credentialsError = $this->checkAccountPassword($user, (string) $request->password);
        if ($credentialsError) {
            return $credentialsError;
        }

        // Cache the phone number for the verification step (10 minutes).
        $cacheKey = "wallet_creation_{$user->id}";
        Cache::put($cacheKey, [
            'user_id' => $user->id,
            'phone_number' => $request->phone_number,
        ], 600);

        return $this->sendWalletOtp($user, $cacheKey);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // STEP 2 – Verify OTP and create wallet
    // POST /api/wallet/verify-and-create
    //
    // Only `otp_code` is required.  The user's email comes from the JWT and
    // the phone number is retrieved from the cache set in step 1.
    // ─────────────────────────────────────────────────────────────────────────
    public function verifyAndCreateWallet(Request $request): JsonResponse
    {
        $validationError = $this->validateVerifyRequest($request);
        if ($validationError) {
            return $validationError;
        }

        // the authenticated user, hydrated once by the JWT middleware
        $user = $request->user();
        // RV-37 / un9: the authenticated user comes from JwtAuthMiddleware's cached
        // hydration, which does not eager-load relations. Every $user->wallet read below
        // would be a lazy load (one query each) - load it once, explicitly.
        $user->loadMissing('wallet');

        if ($user->wallet) {
            return $this->walletAlreadyExistsResponse();
        }

        // Retrieve cached phone number from step 1.
        $cacheKey = "wallet_creation_{$user->id}";
        $walletData = Cache::get($cacheKey);

        if (! $walletData) {
            return response()->json([
                'success' => false,
                'message' => 'Session expired. Please start the wallet creation process again.',
            ], 400);
        }

        $otpError = $this->verifyWalletOtp($user, (string) $request->otp_code);
        if ($otpError) {
            return $otpError;
        }

        return $this->createWalletFor($user, $walletData, $cacheKey);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET /api/wallet/balance
    // ─────────────────────────────────────────────────────────────────────────
    public function getBalance(Request $request): JsonResponse
    {
        // RV-28 (primary-only read): a wallet BALANCE is money-facing — it must reflect the
        // authoritative ledger, not a possibly-lagging replica. The request user is already
        // resolved; `wallet` is loaded on the primary so a read right after a payment/charge
        // can never show a stale figure. Decision-free: it can only make the reported balance
        // more correct, never less.
        $user = $request->user()->load(['wallet' => fn ($q) => $q->useWritePdo()]);

        if (! $user->wallet) {
            return response()->json([
                'success' => false,
                'message' => 'No wallet found for this account.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'wallet_number' => $user->wallet->wallet_number,
            'balance' => $user->wallet->balance,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Mask email for the response hint: ab***@gmail.com
     */
    private function maskEmail(string $email): string
    {
        [$local, $domain] = explode('@', $email, 2);

        $visible = min(2, strlen($local));
        $masked = substr($local, 0, $visible).str_repeat('*', max(0, strlen($local) - $visible));

        return $masked.'@'.$domain;
    }

    /**
     * One wallet per account — shared by both wallet-creation steps.
     */
    private function walletAlreadyExistsResponse(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'You already have a wallet.',
        ], 409);
    }

    /**
     * Step 1 validation: phone number + account password.
     * Returns the 422 response, or null when the input is valid.
     */
    private function validateInitiateRequest(Request $request): ?JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'phone_number' => 'required|string|unique:wallets,phone_number',
            'password' => 'required|string',
        ], [
            'phone_number.required' => 'A phone number is required to create a wallet.',
            'phone_number.unique' => 'This phone number is already linked to another wallet.',
            'password.required' => 'Your account password is required to create a wallet.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        return null;
    }

    /**
     * Step 2 validation: the 6-digit OTP code.
     * Returns the 422 response, or null when the input is valid.
     */
    private function validateVerifyRequest(Request $request): ?JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'otp_code' => ['required', 'string', 'size:6', 'regex:/^[0-9]{6}$/'],
        ], [
            'otp_code.required' => 'Verification code is required.',
            'otp_code.size' => 'Code must be exactly 6 digits.',
            'otp_code.regex' => 'Code must contain numbers only.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        return null;
    }

    /**
     * Re-confirm the account password. Returns the 401 response, or null when it matches.
     */
    private function checkAccountPassword($user, string $password): ?JsonResponse
    {
        // Re-confirm the account password before starting wallet creation. The JWT-cached user
        // deliberately omits the password hash (decision un11), so read it fresh from the primary.
        $passwordHash = $this->walletProvisioning->passwordHashFor($user->id);
        if (! $passwordHash || ! Hash::check($password, $passwordHash)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid credentials',
                'code' => 'INVALID_CREDENTIALS',
            ], 401);
        }

        return null;
    }

    /**
     * Send the OTP to the user's Gmail and build the step-1 response.
     */
    private function sendWalletOtp($user, string $cacheKey): JsonResponse
    {
        // Send OTP to the user's Gmail (identical to signup / email verification).
        $dto = new SendEmailOtpDTO(
            email: Email::from($user->email),
            userName: $user->first_name,
            type: 'EMAIL_VERIFICATION',   // isolated from signup OTPs
        );
        $result = $this->emailOtpService->sendOtp($dto);

        if (! $result['success']) {
            Cache::forget($cacheKey);

            return response()->json([
                'success' => false,
                'message' => 'Failed to send verification email. Please try again.',
            ], 500);
        }

        $response = [
            'success' => true,
            'message' => "A 6-digit verification code has been sent to {$user->email}. It expires in 10 minutes.",
            'email_hint' => $this->maskEmail($user->email),
        ];

        // Expose the code only when EMAIL_OTP_MODE != production (dev / local).
        if (isset($result['otp_code'])) {
            $response['otp_code'] = $result['otp_code'];
        }

        return response()->json($response);
    }

    /**
     * Verify the OTP against the user's email. Returns the 400 response, or null when valid.
     */
    private function verifyWalletOtp($user, string $otpCode): ?JsonResponse
    {
        // Verify OTP against the user's email — same DTO / service as signup.
        $dto = new VerifyEmailOtpDTO(
            email: Email::from($user->email),
            otpCode: $otpCode,
        );
        $result = $this->emailOtpService->verifyOtp($dto);

        if (! $result['success']) {
            return response()->json([
                'success' => false,
                'message' => $result['message'] ?? 'Invalid or expired code.',
            ], 400);
        }

        return null;
    }

    /**
     * The OTP is valid: create the wallet, link it to the user, drop the cache entry.
     *
     * @param  array<string, mixed>  $walletData
     */
    private function createWalletFor($user, array $walletData, string $cacheKey): JsonResponse
    {
        // OTP valid — create wallet and link it to the user.
        $wallet = $this->walletProvisioning->createFor($user, $walletData['phone_number']);

        Cache::forget($cacheKey);

        return response()->json([
            'success' => true,
            'message' => 'Wallet created successfully.',
            'wallet_number' => $wallet['wallet_number'],
            'phone_number' => $wallet['phone_number'],
        ], 201);
    }

    // POST /api/wallet/create-direct
    public function createDirect(Request $request): JsonResponse
    {
        $user = $request->user();
        // RV-37 / un9: the authenticated user comes from JwtAuthMiddleware's cached
        // hydration, which does not eager-load relations. Every $user->wallet read below
        // would be a lazy load (one query each) - load it once, explicitly.
        $user->loadMissing('wallet');

        if ($user->wallet) {
            return response()->json([
                'success' => false,
                'message' => 'You already have a wallet.',
            ], 409);
        }

        $validator = Validator::make($request->all(), [
            'phone_number' => 'required|string|unique:wallets,phone_number',
        ], [
            'phone_number.required' => 'A phone number is required.',
            'phone_number.unique' => 'This phone number is already linked to another wallet.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $wallet = $this->walletProvisioning->createFor($user, $request->phone_number);

        return response()->json([
            'success' => true,
            'message' => 'Wallet created successfully.',
            'wallet_number' => $wallet['wallet_number'],
            'phone_number' => $wallet['phone_number'],
            'balance' => 0,
        ], 201);
    }

    public function transactions(Request $request)
    {
        $user = $request->user();
        // RV-37 / un9: the authenticated user comes from JwtAuthMiddleware's cached
        // hydration, which does not eager-load relations. Every $user->wallet read below
        // would be a lazy load (one query each) - load it once, explicitly.
        $user->loadMissing('wallet');
        $wallet = $user->wallet;

        if (! $wallet) {
            return response()->json([
                'message' => 'No wallet found for this user.',
            ], 404);
        }

        $request->validate([
            'type' => ['nullable', 'string'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $perPage = min((int) ($request->per_page ?? 15), 100);

        $transactions = $wallet->transactions()
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->type))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('created_at', '>=', $request->from))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('created_at', '<=', $request->to))
            ->latest()
            ->paginate($perPage);

        return WalletTransactionResource::collection($transactions)
            ->additional([
                'meta' => [
                    'balance' => number_format((float) $wallet->balance, 2, '.', ''),
                    'cash_ride_debt' => number_format((float) ($wallet->cash_ride_debt ?? 0), 2, '.', ''),
                ],
            ]);
    }
}
