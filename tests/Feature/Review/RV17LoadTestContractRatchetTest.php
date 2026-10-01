<?php

namespace Tests\Feature\Review;

use App\Http\Controllers\API\RideController;
use App\Http\Requests\BookRideRequest;
use App\Http\Requests\SendOtpRequest;
use Tests\TestCase;

/**
 * RV-17 ratchet — the k6 load scripts must speak the REAL API contract, and must not
 * count contract violations as successful requests.
 *
 * Root cause (verified against the controllers/requests, not from memory):
 *   - GET /api/rides/search requires source_lat|source_lng|dest_lat|dest_lng|
 *     departure_date|seats_required; the scripts sent pickup_lat/pickup_lng/
 *     destination_lat/destination_lng/seats.
 *   - POST /api/rides/{id}/book requires seats + communication_number
 *     (BookRideRequest::rules); the scripts sent seats + pickup_lat/pickup_lng.
 *   - POST /api/rides/create-with-route requires pickup_lat, pickup_lng, destination_lat,
 *     destination_lng, departure_time, available_seats, price_per_seat, vehicle_type,
 *     payment_method, booking_type and communication_number; the scripts sent
 *     from_lat/to_lat/origin_lat and omitted four required fields.
 *   - POST /api/otp/send requires phone_number matching /^(+963|963|0)?9[0-9]{8}$/
 *     (SendOtpRequest); the scripts sent {phone: '+96277…'} — wrong key AND a 7-digit
 *     local part that fails the regex.
 * Every one of those returns 422 BEFORE business logic. The scripts additionally listed
 * 422 in http.expectedStatuses(...) and only counted >=500 as an error, so a run in which
 * the majority of the weighted mix was rejected by validation still reported a healthy
 * rps/p95 — the published numbers measured rejection, not the app.
 *
 * This ratchet reads the ACTUAL validation rules and asserts the scripts' request shapes
 * match them, so if an endpoint contract changes this test fails until the load scripts
 * are updated with it. It also asserts 4xx is never treated as an expected response.
 *
 * Scope note: "Syride smoke test.js" is deliberately EXCLUDED — it is a negative-path
 * harness ("422 expected with fake data"), so contract-violating payloads there are the
 * point of the test, not a defect. It is listed here explicitly so the exclusion is
 * visible and cannot silently widen.
 */
class RV17LoadTestContractRatchetTest extends TestCase
{
    /** Load scripts that must honour the API contract. */
    private const LOAD_SCRIPTS = [
        'Syride-70pct-stage1.js',
        'Syride-stage1-900vu-confirm.js',
        'syride-breakpoint-test.js',
        'syride-capacity-validation-test.js',
        'syride-hammer-test.js',
        'syride-spike-only.js',
    ];

    /** Deliberately negative-path, therefore exempt (see class docblock). */
    private const EXEMPT = ['Syride smoke test.js'];

    private function script(string $name): string
    {
        $path = base_path('k6-load/'.$name);
        $this->assertFileExists($path, "k6-load/{$name} must exist");

        return (string) file_get_contents($path);
    }

    /** @test */
    public function the_search_contract_really_is_source_dest_seats_required(): void
    {
        // Ground the ratchet: read the live rules so this test cannot pass on stale text.
        $rules = (new \ReflectionMethod(RideController::class, 'searchRides'))
            ->getFileName();
        $this->assertNotNull($rules);
        $src = (string) file_get_contents((string) $rules);

        foreach (['source_lat', 'source_lng', 'dest_lat', 'dest_lng', 'departure_date', 'seats_required'] as $param) {
            $this->assertStringContainsString("'{$param}'", $src,
                "precondition: searchRides must require {$param} (contract changed — update this ratchet)");
        }
    }

    /** @test */
    public function the_book_contract_really_requires_communication_number(): void
    {
        $rules = (new BookRideRequest)->rules();
        $this->assertArrayHasKey('communication_number', $rules);
        $this->assertArrayHasKey('seats', $rules);
    }

    /** @test */
    public function the_otp_send_contract_really_is_phone_number(): void
    {
        $rules = (new SendOtpRequest)->rules();
        $this->assertArrayHasKey('phone_number', $rules);
        $this->assertArrayNotHasKey('phone', $rules,
            'precondition: the field is phone_number; a bare "phone" key is the RV-17 bug');
    }

    /** @test */
    public function no_load_script_sends_the_old_search_parameter_names(): void
    {
        foreach (self::LOAD_SCRIPTS as $name) {
            $src = $this->script($name);

            // The search request must not use pickup_lat/pickup_lng/destination_* /seats=
            // (the old, wrong contract). A bare `seats=` is also the old shape; the new
            // contract uses seats_required.
            $this->assertDoesNotMatchRegularExpression('/rides\/search[^`]*\?pickup_lat=/', $src,
                "{$name}: search must use source_lat, not pickup_lat");
            $this->assertDoesNotMatchRegularExpression('/&destination_lat=/', $src,
                "{$name}: search must use dest_lat, not destination_lat");
            $this->assertDoesNotMatchRegularExpression('/&destination_lng=/', $src,
                "{$name}: search must use dest_lng, not destination_lng");
            $this->assertDoesNotMatchRegularExpression('/[?&]seats=/', $src,
                "{$name}: search must use seats_required, not seats");
        }
    }

    /** @test */
    public function every_book_request_includes_communication_number(): void
    {
        foreach (self::LOAD_SCRIPTS as $name) {
            $src = $this->script($name);

            // `seats` appears in exactly ONE payload shape across the load scripts — the
            // /book body (search sends `seats_required`, create-with-route sends
            // `available_seats`, /bookings/…/accept sends `{}`). So every object literal
            // carrying `seats:` is a BookRideRequest body and must carry
            // communication_number (BookRideRequest::rules requires it).
            preg_match_all('/JSON\.stringify\(\{([^}]*)\}\)/s', $src, $m);

            foreach ($m[1] as $body) {
                if (! preg_match('/\bseats\s*:/', $body)) {
                    continue; // not a /book body
                }
                $this->assertStringContainsString('communication_number', $body,
                    "{$name}: a /book body (it sends seats:) is missing communication_number, "
                    .'which BookRideRequest requires — it would 422 before business logic');
            }
        }
    }

    /** @test */
    public function no_load_script_uses_the_wrong_otp_field(): void
    {
        foreach (self::LOAD_SCRIPTS as $name) {
            $src = $this->script($name);

            // otp/send must send phone_number with a 9-digit local part.
            $this->assertDoesNotMatchRegularExpression('/JSON\.stringify\(\{\s*phone\s*\}\s*\)/', $src,
                "{$name}: otp/send must send phone_number, not phone");
            // The old format was +96277 + 7 digits, which fails /^(+963|963|0)?9[0-9]{8}$/.
            $this->assertStringNotContainsString('+96277${', $src,
                "{$name}: +96277 (7-digit local) fails the SendOtpRequest regex");
        }
    }

    /** @test */
    public function four_xx_is_never_treated_as_an_expected_success(): void
    {
        foreach (self::LOAD_SCRIPTS as $name) {
            $src = $this->script($name);

            // The response callback must declare ONLY 2xx expected; listing 4xx (esp. 422)
            // is what let contract-violating requests count as successful.
            if (preg_match('/http\.expectedStatuses\((.*?)\)\s*\)/s', $src, $m)) {
                $this->assertDoesNotMatchRegularExpression('/\b4\d\d\b/', $m[1],
                    "{$name}: expectedStatuses must not include 4xx codes; only 2xx is a success "
                    .'(RV-17: 422-as-expected hid contract violations)');
            }
        }
    }
}
