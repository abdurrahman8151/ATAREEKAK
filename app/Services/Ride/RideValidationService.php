<?php

namespace App\Services\Ride;

use App\Models\User;
use App\Models\UserScore;
use App\Services\Verification\DocumentVerificationService;
use Carbon\Carbon;

final class RideValidationService
{
    // Score thresholds from SRS v5
    private const MIN_SCORE_CREATE_RIDE = 50;

    private const MIN_SCORE_BOOK_RIDE = 40;

    public function __construct(
        private readonly DocumentVerificationService $documentService
    ) {}

    public function validateDriverCanCreateRide(User $driver): void
    {
        if (! $driver->is_verified_driver) {
            throw new \InvalidArgumentException('You must be verified as a driver to create rides');
        }

        if (! $driver->profile) {
            throw new \InvalidArgumentException('Driver profile not found. Please complete your profile.');
        }

        $this->documentService->validateDriverDocuments($driver);

        // Score gate
        $score = UserScore::where('user_id', $driver->id)->value('score') ?? 70;
        if ($score < self::MIN_SCORE_CREATE_RIDE) {
            throw new \InvalidArgumentException(
                "Your trust score ({$score}) is too low to create rides. ".
                'Minimum required: '.self::MIN_SCORE_CREATE_RIDE.'. '.
                'Complete rides without cancelling to raise your score.'
            );
        }
    }

    public function validatePassengerCanBook(User $passenger): void
    {
        if (! $passenger->is_verified_passenger) {
            throw new \InvalidArgumentException('You must be verified as a passenger to book rides');
        }

        // Score gate
        $score = UserScore::where('user_id', $passenger->id)->value('score') ?? 70;
        if ($score < self::MIN_SCORE_BOOK_RIDE) {
            throw new \InvalidArgumentException(
                "Your trust score ({$score}) is too low to book rides. ".
                'Minimum required: '.self::MIN_SCORE_BOOK_RIDE.'.'
            );
        }
    }

    public function validateDepartureTime(Carbon $departureTime): void
    {
        $now = Carbon::now('Asia/Damascus');
        $minimumTime = $now->copy()->addMinutes(5);

        if ($departureTime->lte($minimumTime)) {
            throw new \InvalidArgumentException(
                'Departure time must be at least 5 minutes in the future. '.
                'Current time: '.$now->format('Y-m-d H:i:s').' (Damascus time)'
            );
        }

        if ($departureTime->gte($now->copy()->addDays(30))) {
            throw new \InvalidArgumentException(
                'Departure time cannot be more than 30 days in the future'
            );
        }
    }

    public function validateSeatsAvailable(int $requested, int $available): void
    {
        if ($requested < 1) {
            throw new \InvalidArgumentException('Must request at least 1 seat');
        }
        if ($requested > 8) {
            throw new \InvalidArgumentException('Cannot request more than 8 seats per booking');
        }
        if ($requested > $available) {
            throw new \InvalidArgumentException(
                "Not enough seats available. Requested: {$requested}, Available: {$available}"
            );
        }
    }

    public function validateCanCancelRide(Carbon $departureTime): void
    {
        /*   $now = Carbon::now('Asia/Damascus');
           if ($now->diffInHours($departureTime, false) < 1) {
               throw new \InvalidArgumentException(
                   'Cannot cancel ride less than 1 hour before departure time'
               );
           }*/
    }

    public function validateCanCancelBooking(Carbon $rideDepartureTime): void
    {
        $now = Carbon::now('Asia/Damascus');
        if ($now->diffInHours($rideDepartureTime, false) < 2) {
            throw new \InvalidArgumentException(
                'Cannot cancel booking less than 2 hours before departure time'
            );
        }
    }
}
