<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    public const HOME = '/home';

    public function boot(): void
    {
        $this->configureRateLimiting();

        $this->routes(function () {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        });
    }

    protected function configureRateLimiting(): void
    {
        $enabled = config('rate-limiting.enabled', true);
        $limits = config('rate-limiting.limits', []);
        $multiplier = max(1, (int) config('rate-limiting.ip_backstop_multiplier', 4));

        foreach ($limits as $name => $perMinute) {
            RateLimiter::for($name, function (Request $request) use ($enabled, $perMinute, $multiplier) {
                if (! $enabled) {
                    return Limit::none();
                }

                // T2-10: keying purely on `user()?->id ?: ip()` made the public
                // auth endpoints IP-only. That is wrong in both directions: an
                // attacker rotating source addresses got a fresh allowance each
                // time (defeating the per-account OTP cap, T2-3), while
                // legitimate users behind one NAT/carrier gateway shared a single
                // strict bucket and throttled each other out.
                //
                // Three cases now, so we add security without changing the
                // behaviour of endpoints that were already correctly keyed:
                //
                //   1. Authenticated                 -> one user-id bucket (unchanged).
                //   2. Public with a stable identity  -> a STRICT per-identity bucket
                //      PLUS a separate, higher per-IP flood-guard bucket, so both
                //      limits apply (the audit's recommended shape).
                //   3. Public without identity (e.g. /auth/refresh, which carries
                //      only a token) -> per-IP at the category limit (unchanged).
                if ($user = $request->user()) {
                    return Limit::perMinute($perMinute)->by('user:'.$user->getAuthIdentifier());
                }

                $identity = $this->identityKey($request);

                if ($identity !== null) {
                    return [
                        // Strict, stable, shared across every source address the
                        // attacker rotates through — this is what actually caps
                        // brute force against one account.
                        Limit::perMinute($perMinute)->by('account:'.$identity),
                        // Looser per-address ceiling so a busy shared gateway is
                        // not starved, while a single-IP hammer is still bounded.
                        Limit::perMinute($perMinute * $multiplier)->by('ip:'.($request->ip() ?: 'noip')),
                    ];
                }

                return Limit::perMinute($perMinute)->by('ip:'.($request->ip() ?: 'noip'));
            });
        }
    }

    /**
     * Derive a stable bucket key for an UNAUTHENTICATED request from whatever
     * account identifier it carries. This is a cache-bucket label, not
     * validation: ThrottleRequests md5s the composed key (shouldHashKeys is on
     * by default), so no raw email/phone is ever persisted to the store, and
     * every branch here is string-only so a malformed field can NEVER throw and
     * turn a 429 into a 500 on a public endpoint.
     *
     * Phone spellings are canonicalised to the last 9 national digits so that
     * +963983337214, 963983337214 and 0983337214 collapse to one bucket —
     * otherwise respelling the same number would hand an attacker a fresh
     * allowance, the exact bypass T2-10 is about.
     */
    private function identityKey(Request $request): ?string
    {
        // Email first (login/signup/password-forgot/verify all use it), then the
        // OTP phone field, then the staff/admin "identifier"/"username" fields.
        $email = trim((string) $request->input('email', ''));
        if ($email !== '' && str_contains($email, '@')) {
            return 'email:'.strtolower($email);
        }

        $phone = preg_replace('/\D/', '', (string) $request->input('phone_number', ''));
        if ($phone !== '' && $phone !== null) {
            return 'phone:'.substr($phone, -9);
        }

        foreach (['identifier', 'username'] as $field) {
            $value = trim((string) $request->input($field, ''));
            if ($value !== '') {
                return $field.':'.strtolower($value);
            }
        }

        return null;
    }
}
