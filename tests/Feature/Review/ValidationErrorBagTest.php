<?php

namespace Tests\Feature\Review;

use App\Exceptions\Handler;
use Tests\TestCase;

/**
 * RV-13 — the API error model.
 *
 * V5 recorded: `App\Exceptions\Handler::register()` installs a catch-all
 * `renderable(function (Throwable $e, $request))`. For an `api/*` request it maps
 * `ValidationException` to 422 and then REBUILDS the response as
 * `{"status":"error","message":…,"code":422}` — dropping `$e->errors()`.
 *
 * That is the defect: a validation failure is the one error a client most needs to
 * act on, and this shape tells it only that something failed, never which field or
 * why. Every FormRequest and `$request->validate()` endpoint in the app is
 * affected (BookRideRequest, the OTP requests, Wallet*Request, `searchRides`,
 * `cancelPartialSeats`, `bulkAction`, …).
 *
 * Laravel's own default rendering of a ValidationException is
 * `{"message": …, "errors": {"field": ["reason", …]}}`. This test pins the
 * app-specific requirement on top of that: the bag must be present, keyed by field.
 *
 * @see Handler::register()
 */
class ValidationErrorBagTest extends TestCase
{
    /** @test */
    public function a_validation_failure_returns_422_with_an_errors_bag_keyed_by_field(): void
    {
        $response = $this->postJson('/api/otp/send', ['phone_number' => 'not-a-phone']);

        $response->assertStatus(422);
        $response->assertJsonStructure(['errors']);
        $response->assertJsonStructure(['errors' => ['phone_number']]);
    }

    /** @test */
    public function the_errors_bag_explains_why_the_field_failed(): void
    {
        $response = $this->postJson('/api/otp/send', ['phone_number' => 'not-a-phone']);

        $response->assertStatus(422);

        // A bare "true" is not enough — the reason must be a human-readable string.
        $messages = $response->json('errors.phone_number');
        $this->assertIsArray($messages, 'errors.phone_number must be a list of messages');
        $this->assertNotEmpty($messages);
        $this->assertIsString($messages[0]);
        $this->assertNotSame('', trim($messages[0]));
    }

    /** @test */
    public function every_invalid_field_appears_in_the_bag_not_just_the_first(): void
    {
        // `type` is invalid AND `phone_number` is missing, so both must be reported.
        // Reporting only the first failure is a classic symptom of the same defect.
        $response = $this->postJson('/api/otp/send', ['type' => 'nonsense']);

        $response->assertStatus(422);

        $errors = $response->json('errors');
        $this->assertIsArray($errors);
        $this->assertArrayHasKey('phone_number', $errors, 'a missing required field must be reported');
        $this->assertArrayHasKey('type', $errors, 'an invalid enum value must be reported');
    }

    /**
     * The catch-all must not swallow the HTTP status of a real HTTP exception.
     *
     * Pinned here because the same catch-all rebuilds every response, so a fix that
     * restores the validation bag could easily disturb status/header handling.
     * 404 comes from an unrouted/denied path rather than a thrown exception, so it
     * travels a different path and must stay a clean 404.
     *
     * @test
     */
    public function an_unknown_api_route_still_returns_a_404_json_body(): void
    {
        $response = $this->getJson('/api/rv13-definitely-not-a-route');

        $response->assertStatus(404);
        $this->assertIsArray($response->json());
    }
}
