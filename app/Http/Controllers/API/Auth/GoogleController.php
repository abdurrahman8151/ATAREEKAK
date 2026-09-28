<?php

namespace App\Http\Controllers\API\Auth;

use App\Http\Controllers\Controller;
use App\Interfaces\UserRepositoryInterface;
use App\Models\User;
use App\Services\JwtService; // Ensure this is imported
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;

class GoogleController extends Controller
{
    private UserRepositoryInterface $userRepo;

    private JwtService $jwtService;

    public function __construct(UserRepositoryInterface $userRepo, JwtService $jwtService)
    {
        $this->userRepo = $userRepo;
        $this->jwtService = $jwtService;
    }

    public function redirect()
    {
        return Socialite::driver('google')->redirect();
    }

    // Inject Illuminate\Http\Request to inspect it
    public function callback(Request $request)
    {
        // Log all incoming request data (query parameters and POST body)
        Log::info('Google Callback - Incoming Request Data:', $request->all());
        Log::info('Google Callback - Query Param "code":', ['code_param' => $request->query('code')]);
        Log::info('Google Callback - Query Param "state":', ['state_param' => $request->query('state')]);

        try {
            $guzzleClientOptions = [];
            if (config('app.env') === 'local' || config('app.env') === 'testing') {
                Log::warning('Google OAuth: SSL verification is DISABLED for Guzzle client. FOR TESTING ONLY.');
                $guzzleClientOptions['verify'] = false;
            }
            $client = new Client($guzzleClientOptions);

            // Socialite should automatically pick up the 'code' from the $request
            $googleUser = Socialite::driver('google')
                ->setHttpClient($client)
                ->user();

            // ... (rest of your existing logic from the previous version)
            if (! $googleUser || ! $googleUser->getEmail()) {
                Log::error('Google OAuth Callback: Google user data or email not received.', ['google_user_dump' => $googleUser]);

                return response()->json(['error' => 'Could not retrieve user information from Google.'], 401);
            }

            $user = $this->userRepo->findByGoogleId($googleUser->getId());

            if (! $user) {
                $user = $this->userRepo->findByEmail($googleUser->getEmail());

                if ($user) {
                    $this->userRepo->updateGoogleId($user->id, $googleUser->getId());
                    if (empty($user->avatar) && $googleUser->getAvatar()) {
                        $userModel = User::find($user->id);
                        if ($userModel) {
                            $userModel->avatar = $googleUser->getAvatar();
                            $userModel->save();
                            $user = $userModel;
                        }
                    }
                } else {
                    $firstName = $googleUser->user['given_name'] ?? null;
                    $lastName = $googleUser->user['family_name'] ?? null;

                    if (is_null($firstName) && ! is_null($googleUser->getName())) {
                        $nameParts = explode(' ', $googleUser->getName(), 2);
                        $firstName = $nameParts[0];
                        $lastName = $nameParts[1] ?? '';
                    }

                    $userData = [
                        'first_name' => $firstName,
                        'last_name' => $lastName,
                        'email' => $googleUser->getEmail(),
                        'password' => bcrypt(Str::random(24)),
                        'google_id' => $googleUser->getId(),
                        'avatar' => $googleUser->getAvatar(),
                        'email_verified_at' => now(),
                        'status' => 1,
                    ];
                    $user = $this->userRepo->createUser($userData);
                }
            } else {
                $updateData = [];
                if ($user->avatar !== $googleUser->getAvatar() && $googleUser->getAvatar()) {
                    $updateData['avatar'] = $googleUser->getAvatar();
                }
                if (! empty($updateData)) {
                    $userModel = User::find($user->id);
                    if ($userModel) {
                        $userModel->update($updateData);
                        $user = $userModel;
                    }
                }
            }

            if (! $user) {
                Log::error('Google OAuth Callback: User object is null after create/find.', ['google_user_id' => $googleUser->getId()]);

                return response()->json(['error' => 'User processing failed after Google authentication.'], 500);
            }

            // ── Block banned accounts BEFORE issuing any credential ──────────
            // Parity with LoginController:77-83. This matters specifically
            // because of T2-9: the token below is now a REAL credential that
            // the jwt middleware accepts, so without this gate a suspended
            // account finishing Google OAuth would be handed a working access
            // token plus a 7-day refresh-token row. (The middleware still
            // refuses status == -1 on every request, so this closes the
            // credential-issuance step rather than a bypass.)
            if ((int) $user->status === -1) {
                Log::warning('Google OAuth Callback: refused to issue tokens for a banned account.', [
                    'user_id' => $user->id,
                ]);

                return response()->json([
                    'status' => 'error',
                    'message' => 'Your account has been suspended. Please contact support.',
                    'code' => 'ACCOUNT_BANNED',
                ], 403);
            }

            // Parity with LoginController:86-88, and required for the fix to
            // actually work: LogoutController:39 leaves a signed-out account at
            // status 0, and JwtAuthMiddleware:97 rejects status 0 with
            // USER_INACTIVE. Completing Google OAuth IS an authentication
            // event, so it must clear the "logged out" flag exactly as password
            // login does — otherwise the token issued below would be rejected
            // by the very middleware it is meant to satisfy.
            $this->userRepo->updateUserStatus($user->id, 1);
            $user->refresh();

            // T2-9: this used to be $user->createToken('google-auth-token'), i.e.
            // a Sanctum personal-access token. Every protected endpoint is
            // guarded by the custom `jwt` middleware, which decodes with
            // JwtService and requires a `type === 'access'` claim; a Sanctum
            // token has none of those claims, so the value returned here was
            // accepted by nothing and Google sign-in was broken end-to-end.
            // Issue the same access/refresh pair LoginController:90 issues.
            $tokens = $this->jwtService->generateTokenPair($user);

            // `tokens` matches the rest of the auth API (LoginController). The
            // flat `token` / `token_type` keys are KEPT for backward
            // compatibility: they were the only shape this endpoint ever
            // returned, the consuming client is outside this repository and so
            // cannot be checked from here, and a client reading `token` now
            // receives a credential that actually works.
            return response()->json([
                'message' => 'Authentication successful.',
                'user' => $user,
                'token' => $tokens['access_token'],
                'token_type' => $tokens['token_type'],
                'tokens' => $tokens,
            ]);

        } catch (InvalidStateException $e) {
            Log::warning('Google OAuth Callback Invalid State: '.$e->getMessage(), [
                'exception' => $e,
                'incoming_state' => $request->input('state'), // Log the state Socialite received
            ]);

            return response()->json(['error' => 'Invalid state. Please try logging in again.'], 401);
        } catch (ClientException $e) {
            $responseBody = $e->hasResponse() ? $e->getResponse()->getBody()->getContents() : 'No response body';
            Log::error('Google OAuth Callback Guzzle Client Error: '.$e->getMessage(), [
                'exception' => $e,
                'response_body' => $responseBody,
            ]);
            $errorMessage = 'Authentication failed due to a communication error with Google.';
            if (config('app.debug')) {
                $errorMessage .= ' Guzzle Error: '.$e->getMessage().' | Response: '.$responseBody;
            }

            return response()->json(['error' => $errorMessage], 401);
        } catch (RequestException $e) {
            Log::error('Google OAuth Callback Guzzle Request (cURL) Error: '.$e->getMessage(), [
                'exception' => $e,
                'handler_context' => method_exists($e, 'getHandlerContext') ? $e->getHandlerContext() : 'N/A',
            ]);
            $errorMessage = 'Authentication failed due to a network issue (cURL).';
            if (config('app.debug')) {
                $errorMessage .= ' Details: '.$e->getMessage();
            }

            return response()->json(['error' => $errorMessage], 401);
        } catch (\Exception $e) {
            Log::error('Google OAuth Callback General Error: '.$e->getMessage(), [
                'exception' => $e,
                'trace' => $e->getTraceAsString(),
            ]);
            $errorMessage = 'Authentication failed. Please try again later.';
            if (config('app.debug')) {
                $errorMessage .= ' Details: '.get_class($e).' - '.$e->getMessage();
            }

            return response()->json(['error' => $errorMessage], 401);
        }
    }
}
