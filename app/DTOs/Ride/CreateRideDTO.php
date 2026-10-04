<?php

namespace App\DTOs\Ride;

use App\Domain\ValueObjects\Location;
use App\Domain\ValueObjects\Money;
use App\Domain\ValueObjects\PhoneNumber;
use App\Enums\BookingType;
use App\Enums\PaymentMethod;
use Carbon\Carbon;

/**
 * Data Transfer Object for ride creation
 *
 * FIXED: Added calculateTotalValue() and calculateRideCreationFee()
 *
 * Ensures type safety and eliminates array passing
 */
final class CreateRideDTO
{
    public function __construct(
        public readonly int $driverId,
        public readonly Location $pickupLocation,
        public readonly Location $destinationLocation,
        public readonly string $pickupAddress,
        public readonly string $destinationAddress,
        public readonly Carbon $departureTime,
        public readonly int $availableSeats,
        public readonly Money $pricePerSeat,
        public readonly string $vehicleType,
        public readonly PaymentMethod $paymentMethod,
        public readonly BookingType $bookingType,
        public readonly PhoneNumber $communicationNumber,
        public readonly ?string $notes = null,
        public readonly ?array $routeGeometry = null,
        public readonly ?int $chosenRouteIndex = null,
        public readonly ?float $distance = null,
        public readonly ?float $duration = null,
    ) {}

    /**
     * Create DTO from validated request data
     */
    public static function fromRequest(array $validated, int $userId): self
    {
        // Handle pickup location
        $pickupLocation = isset($validated['pickup_lat'], $validated['pickup_lng'])
            ? Location::fromCoordinates($validated['pickup_lat'], $validated['pickup_lng'])
            : Location::fromArray($validated['pickup_location']);

        // Handle destination location
        $destinationLocation = isset($validated['destination_lat'], $validated['destination_lng'])
            ? Location::fromCoordinates($validated['destination_lat'], $validated['destination_lng'])
            : Location::fromArray($validated['destination_location']);

        return new self(
            driverId: $userId,
            pickupLocation: $pickupLocation,
            destinationLocation: $destinationLocation,
            pickupAddress: $validated['pickup_address'],
            destinationAddress: $validated['destination_address'],
            departureTime: Carbon::parse($validated['departure_time'], 'Asia/Damascus'),
            availableSeats: $validated['available_seats'],
            pricePerSeat: Money::from($validated['price_per_seat']),
            vehicleType: $validated['vehicle_type'],
            paymentMethod: PaymentMethod::from($validated['payment_method']),
            bookingType: BookingType::from($validated['booking_type']),
            communicationNumber: PhoneNumber::from($validated['communication_number']),
            notes: $validated['notes'] ?? null,
            routeGeometry: $validated['route_geometry'] ?? null,
            chosenRouteIndex: $validated['route_index'] ?? null,
            distance: $validated['distance'] ?? null,
            duration: $validated['duration'] ?? null,
        );
    }

    /**
     * Calculate total value of the ride
     * (price per seat × available seats)
     */
    public function calculateTotalValue(): Money
    {
        return $this->pricePerSeat->multiply($this->availableSeats);
    }

    /**
     * Calculate ride creation fee (5% of total value)
     */
    public function calculateRideCreationFee(): Money
    {
        return $this->calculateTotalValue()->percentage(5);
    }

    /**
     * Convert DTO to array for repository
     */
    public function toArray(): array
    {
        return [
            'driver_id' => $this->driverId,
            'pickup_location' => $this->pickupLocation->toArray(),
            'destination_location' => $this->destinationLocation->toArray(),
            'pickup_address' => $this->pickupAddress,
            'destination_address' => $this->destinationAddress,
            'departure_time' => $this->departureTime->toDateTimeString(),
            'available_seats' => $this->availableSeats,
            'price_per_seat' => $this->pricePerSeat->amount(),
            'vehicle_type' => $this->vehicleType,
            'payment_method' => $this->paymentMethod->value,
            'booking_type' => $this->bookingType->value,
            'communication_number' => $this->communicationNumber->number(),
            'notes' => $this->notes,
            'route_geometry' => $this->routeGeometry,
            // RV-14: `rides.chosen_route_index` is NOT NULL. A client that sends no `route_index`
            // left this null, and the INSERT failed with a 500 ("Column 'chosen_route_index' cannot be
            // null") on an otherwise perfectly valid request - verified pre-existing, identical with
            // and without the validation-parity change in sec 62.
            //
            // 0 is the correct value, not a workaround: it is the column's declared DEFAULT and means
            // "the first (only) route". Passing null instead of the default meant the DEFAULT was
            // never actually used on this path.
            'chosen_route_index' => $this->chosenRouteIndex ?? 0,
            'distance' => $this->distance,
            'duration' => $this->duration,
        ];
    }
}
