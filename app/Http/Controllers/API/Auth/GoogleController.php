<?php

namespace App\Http\Controllers\API\Auth;

use App\Http\Controllers\Controller;
use App\Interfaces\UserRepositoryInterface;
use App\Services\Auth\GoogleUserResolver;
use App\Services\JwtService; // Ensure this is imported
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;

class GoogleController extends Controller
{
    private UserRepositoryInterface $userRepo;

    private JwtService $jwtService;

    private GoogleUserResolver $googleUsers;

    public function __construct(UserRepositoryInterface $userRepo, JwtService $jwtService, GoogleUserResolver $googleUsers)
    {
        $this->userRepo = $userRepo;
        $this->jwtService = $jwtService;
        $this->googleUsers = $googleUsers;
    }

    public function redirect()
    {
        return Socialite::driver('google')->redirect();
    }

    // Inject Illuminate\Http\Request to inspect it
    public function callback(Request $request)
    {
        // RV-22 (credential redaction): the OAuth authorization `code` and the CSRF
        // `state` arrive as query params, and `$request->all()` contains BOTH. Logging
        // them wrote a live, single-use `code` — exchangeable for access/refresh tokens
        // for its short TTL — plus the CSRF token, into storage/logs, visible to anyone
        // with read access to the log file or a log aggregator. Debug-era instrumentation
        // ("Log all incoming request data"), never a feature. Failures still log below,
        // but WITHOUT secrets: $request stays injected only for the InvalidState path,
        // which records the exception, never the incoming state/code value.
        try {
            $googleUser = $this->fetchGoogleUser();

            if (! $googleUser || ! $googleUser->getEmail()) {
                Log::error('Google OAuth Callback: Google user data or email not received.', ['google_user_dump' => $googleUser]);

                return response()->json(['error' => 'Could not retrieve user information from Google.'], 401);
            }

            $user = $this->googleUsers->resolve($googleUser);

            if (! $user) {
                Log::error('Google OAuth Callback: User object is null after create/find.', ['google_user_id' => $googleUser->getId()]);

                return response()->json(['error' => 'User processing failed after Google authentication.'], 500);
            }

            $refusal = $this->refuseBannedAccount($user);
            if ($refusal !== null) {
                return $refusal;
            }

            $this->liftExpiredBan($user);

            // Parity with LoginController:86-88, and required for the fix to
            // actually work: LogoutController:39 leaves a signed-out account at
            // status 0, and JwtAuthMiddleware:97 rejects status 0 with
            // USER_INACTIVE. Completing Google OAuth IS an authentication
            // event, so it must clear the "logged out" flag exactly as password
            // login does — otherwise the token issued below would be rejected
            // by the very middleware it is meant to satisfy.
            $this->userRepo->updateUserStatus($user->id, 1);
            $user->refresh();

            return $this->tokensIssuedResponse($user);
        } catch (InvalidStateException $e) {
            return $this->invalidStateResponse($request, $e);
        } catch (ClientException $e) {
            return $this->clientErrorResponse($e);
        } catch (RequestException $e) {
            return $this->requestErrorResponse($e);
        } catch (\Exception $e) {
            return $this->generalErrorResponse($e);
        }
    }

    /**
     * Run the Socialite handshake for the `code` on the request.
     *
     * Separated out so the callback's decision path (ban gate, status flip, token issue)
     * reads on its own, and so the RV-22 transport note sits with the HTTP client it
     * describes.
     */
    private function fetchGoogleUser()
    {
        // RV-22: no verify=false here anymore. It was gated on config('app.env')
        // (local/testing), but an env-gate is exactly the misdetection-prone guard
        // this audit distrusts (RV-16): one env mistake and OAuth TLS silently
        // disables. Verified HTTPS to accounts.google.com works from the deployment
        // box with the configured CA bundle, and tests mock Socialite (no real TLS
        // round-trip), so verification simply stays on.
        $client = new Client([]);

        // Socialite should automatically pick up the 'code' from the $request
        return Socialite::driver('google')
            ->setHttpClient($client)
            ->user();
    }

    /**
     * The banned-account gate, run BEFORE any credential is issued.
     *
     * Returns the 403 refusal, or null when the account is not banned right now.
     */
    private function refuseBannedAccount($user): ?JsonResponse
    {
        // ── Block banned accounts BEFORE issuing any credential ──────────
        // Parity with LoginController — and required since T2-9 made the token
        // below a REAL credential. RV-12: isBannedNow() honours ban_expires_at so
        // an expired TEMPORARY ban does not lock the user out of Google sign-in
        // forever (the same dead-end the password path had); a PERMANENT ban (or
        // one with no expiry) is still refused, exactly as before.
        if (! $user->isBannedNow()) {
            return null;
        }

        Log::warning('Google OAuth Callback: refused to issue tokens for a banned account.', [
            'user_id' => $user->id,
        ]);

        return response()->json([
            'status' => 'error',
            'message' => 'Your account has been suspended. Please contact support.',
            'code' => 'ACCOUNT_BANNED',
        ], 403);
    }

    /**
     * Clear the dead ban columns when the temporary ban has already expired.
     */
    private function liftExpiredBan($user): void
    {
        // RV-12: an expired temporary ban is no longer in force. The status flip
        // below makes it ACTIVE, after which the middleware's auto-lift (a
        // status === -1 branch) can never clear these columns — so, exactly as
        // the password path does, the door that lifts the ban clears the dead
        // fields. (ban_* are all in $fillable, so this write actually persists.)
        if (! $user->banHasExpired()) {
            return;
        }

        $user->update([
            'ban_reason' => null,
            'ban_type' => null,
            'banned_at' => null,
            'ban_expires_at' => null,
            'banned_by' => null,
        ]);
    }

    /**
     * Issue the access/refresh pair and build the 200 body.
     */
    private function tokensIssuedResponse($user): JsonResponse
    {
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
    }

    /**
     * The CSRF-state mismatch refusal (RV-22: presence only, never the value).
     */
    private function invalidStateResponse(Request $request, InvalidStateException $e): JsonResponse
    {
        // RV-22: presence only. The raw state is either attacker-controlled (a real
        // mismatch) or the victim's genuine CSRF token (if their session was lost);
        // logging the value can leak it. The exception message + this flag answer the
        // operational question ("did Google send a state at all?") without a secret.
        Log::warning('Google OAuth Callback Invalid State: '.$e->getMessage(), [
            'exception' => get_class($e),
            'state_present' => $request->has('state'),
        ]);

        return response()->json(['error' => 'Invalid state. Please try logging in again.'], 401);
    }

    /**
     * The Guzzle 4xx/5xx refusal from the token exchange.
     */
    private function clientErrorResponse(ClientException $e): JsonResponse
    {
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
    }

    /**
     * The Guzzle transport (cURL) refusal.
     */
    private function requestErrorResponse(RequestException $e): JsonResponse
    {
        Log::error('Google OAuth Callback Guzzle Request (cURL) Error: '.$e->getMessage(), [
            'exception' => $e,
            'handler_context' => method_exists($e, 'getHandlerContext') ? $e->getHandlerContext() : 'N/A',
        ]);
        $errorMessage = 'Authentication failed due to a network issue (cURL).';
        if (config('app.debug')) {
            $errorMessage .= ' Details: '.$e->getMessage();
        }

        return response()->json(['error' => $errorMessage], 401);
    }

    /**
     * The catch-all refusal.
     */
    private function generalErrorResponse(\Exception $e): JsonResponse
    {
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
