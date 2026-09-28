<?php

namespace Tests\Unit\Http\Requests;

use App\Http\Requests\CreateRideRequest;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * Unit tests for CreateRideRequest.
 *
 * These tests validate the ->rules() array directly against sample
 * payloads via Validator::make(), rather than dispatching a full HTTP
 * request. This keeps the tests fast and isolated from routing/auth.
 */
class CreateRideRequestTest extends TestCase
{
    private function rules(): array
    {
        return (new CreateRideRequest)->rules();
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'pickup_address' => 'Damascus, Old City',
            'destination_address' => 'Homs, City Center',
            'departure_time' => now()->addHour()->toDateTimeString(),
            'available_seats' => 3,
            'price_per_seat' => 5000,
            'payment_method' => 'cash',
            'booking_type' => 'direct',
            'communication_number' => '0912345678',
            'vehicle_type' => 'sedan',
        ], $overrides);
    }

    public function test_authorize_always_returns_true(): void
    {
        $this->assertTrue((new CreateRideRequest)->authorize());
    }

    public function test_passes_with_valid_addresses_and_no_coordinates(): void
    {
        $validator = Validator::make($this->validPayload(), $this->rules());

        $this->assertTrue($validator->passes(), $validator->errors()->__toString());
    }

    public function test_passes_with_coordinates_instead_of_addresses(): void
    {
        $payload = $this->validPayload();
        // FIX: omit address keys entirely rather than setting them to null —
        // 'required_without_all' still runs the 'string' rule against an
        // explicit null value and fails
        unset($payload['pickup_address'], $payload['destination_address']);

        $payload = array_merge($payload, [
            'pickup_lat' => 33.5138,
            'pickup_lng' => 36.2765,
            'destination_lat' => 34.7324,
            'destination_lng' => 36.7137,
        ]);

        $validator = Validator::make($payload, $this->rules());

        $this->assertTrue($validator->passes(), $validator->errors()->__toString());
    }

    public function test_fails_when_pickup_has_neither_address_nor_coordinates(): void
    {
        $payload = $this->validPayload(['pickup_address' => null]);

        $validator = Validator::make($payload, $this->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('pickup_address', $validator->errors()->toArray());
    }

    public function test_fails_when_destination_has_neither_address_nor_coordinates(): void
    {
        $payload = $this->validPayload(['destination_address' => null]);

        $validator = Validator::make($payload, $this->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('destination_address', $validator->errors()->toArray());
    }

    public function test_fails_when_pickup_lat_given_without_pickup_lng(): void
    {
        $payload = $this->validPayload(['pickup_lat' => 33.5138]);

        $validator = Validator::make($payload, $this->rules());

        $this->assertTrue($validator->fails());
        // FIX: the rule 'pickup_lng' => 'required_with:pickup_lat' means the
        // validation error is attached to pickup_lng, not pickup_lat
        $this->assertArrayHasKey('pickup_lng', $validator->errors()->toArray());
    }

    public function test_fails_when_pickup_lat_out_of_range(): void
    {
        $payload = $this->validPayload([
            'pickup_lat' => 95, // invalid: > 90
            'pickup_lng' => 36.2765,
        ]);

        $validator = Validator::make($payload, $this->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('pickup_lat', $validator->errors()->toArray());
    }

    public function test_fails_when_departure_time_is_too_soon(): void
    {
        // Rule requires strictly after now()+5min, so "now" should fail.
        $payload = $this->validPayload(['departure_time' => now()->toDateTimeString()]);

        $validator = Validator::make($payload, $this->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('departure_time', $validator->errors()->toArray());
    }

    public function test_fails_when_departure_time_is_not_a_valid_date(): void
    {
        $payload = $this->validPayload(['departure_time' => 'not-a-date']);

        $validator = Validator::make($payload, $this->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('departure_time', $validator->errors()->toArray());
    }

    public function test_fails_when_available_seats_exceeds_max(): void
    {
        $payload = $this->validPayload(['available_seats' => 9]);

        $validator = Validator::make($payload, $this->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('available_seats', $validator->errors()->toArray());
    }

    public function test_fails_when_available_seats_is_zero(): void
    {
        $payload = $this->validPayload(['available_seats' => 0]);

        $validator = Validator::make($payload, $this->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('available_seats', $validator->errors()->toArray());
    }

    public function test_fails_when_price_per_seat_below_minimum(): void
    {
        $payload = $this->validPayload(['price_per_seat' => 50]); // min is 100

        $validator = Validator::make($payload, $this->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('price_per_seat', $validator->errors()->toArray());
    }

    public function test_fails_when_price_per_seat_above_maximum(): void
    {
        $payload = $this->validPayload(['price_per_seat' => 200000]); // max is 100000

        $validator = Validator::make($payload, $this->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('price_per_seat', $validator->errors()->toArray());
    }

    /**
     * @dataProvider invalidCommunicationNumberProvider
     */
    public function test_fails_on_invalid_syrian_communication_number(string $number): void
    {
        $payload = $this->validPayload(['communication_number' => $number]);

        $validator = Validator::make($payload, $this->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('communication_number', $validator->errors()->toArray());
    }

    public static function invalidCommunicationNumberProvider(): array
    {
        return [
            'missing leading 09' => ['912345678'],
            'wrong prefix' => ['0812345678'],
            'too short' => ['091234567'],
            'too long' => ['09123456789'],
            'contains letters' => ['09abcd5678'],
            'international format' => ['+963912345678'],
        ];
    }

    public function test_passes_on_valid_syrian_communication_number(): void
    {
        $payload = $this->validPayload(['communication_number' => '0987654321']);

        $validator = Validator::make($payload, $this->rules());

        $this->assertTrue($validator->passes(), $validator->errors()->__toString());
    }

    public function test_fails_when_payment_method_is_invalid(): void
    {
        $payload = $this->validPayload(['payment_method' => 'credit_card']);

        $validator = Validator::make($payload, $this->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('payment_method', $validator->errors()->toArray());
    }

    public function test_fails_when_booking_type_is_invalid(): void
    {
        $payload = $this->validPayload(['booking_type' => 'instant']);

        $validator = Validator::make($payload, $this->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('booking_type', $validator->errors()->toArray());
    }

    public function test_fails_when_required_fields_are_missing(): void
    {
        $validator = Validator::make([], $this->rules());

        $this->assertTrue($validator->fails());

        foreach ([
            'pickup_address',
            'destination_address',
            'departure_time',
            'available_seats',
            'price_per_seat',
            'payment_method',
            'booking_type',
            'communication_number',
            'vehicle_type',
        ] as $field) {
            $this->assertArrayHasKey($field, $validator->errors()->toArray(), "Expected error for [{$field}]");
        }
    }

    public function test_optional_route_fields_are_nullable(): void
    {
        $validator = Validator::make($this->validPayload(), $this->rules());

        $this->assertTrue($validator->passes(), $validator->errors()->__toString());
    }

    public function test_passes_with_optional_route_fields_present(): void
    {
        $payload = $this->validPayload([
            'route_index' => 0,
            'route_geometry' => ['type' => 'LineString', 'coordinates' => [[36.2765, 33.5138], [36.7137, 34.7324]]],
            'distance' => 12345.6,
            'duration' => 900.0,
        ]);

        $validator = Validator::make($payload, $this->rules());

        $this->assertTrue($validator->passes(), $validator->errors()->__toString());
    }

    public function test_fails_when_notes_exceeds_max_length(): void
    {
        $payload = $this->validPayload(['notes' => str_repeat('a', 501)]);

        $validator = Validator::make($payload, $this->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('notes', $validator->errors()->toArray());
    }
}
