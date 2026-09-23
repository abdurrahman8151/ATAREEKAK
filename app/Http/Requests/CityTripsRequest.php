<?php

namespace App\Http\Requests;

use App\Enums\SyrianCity;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for GET /api/rides/city-trips
 *
 * Every parameter is optional. With no query string at all the endpoint
 * returns bookable upcoming trips leaving from OR arriving at the
 * authenticated user's own city.
 */
class CityTripsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Auth handled by the `jwt` middleware
    }

    public function rules(): array
    {
        return [
            // ── City / direction ────────────────────────────────────────────
            'city'      => 'sometimes|string|in:' . SyrianCity::validationList(),
            'direction' => 'sometimes|in:from,to,both',

            // ── Seats ───────────────────────────────────────────────────────
            'seats'     => 'sometimes|integer|min:1|max:8',
            'max_seats' => 'sometimes|integer|min:1|max:8|gte:seats',

            // ── Departure window ────────────────────────────────────────────
            'departure_date' => 'sometimes|date',
            'date_from'      => 'sometimes|date',
            'date_to'        => 'sometimes|date|after_or_equal:date_from',

            // ── Price ───────────────────────────────────────────────────────
            'min_price' => 'sometimes|numeric|min:0',
            'max_price' => 'sometimes|numeric|min:0|gte:min_price',

            // ── Ride attributes ─────────────────────────────────────────────
            'payment_method' => 'sometimes|in:cash,e-pay',
            'booking_type'   => 'sometimes|in:direct,request',
            'vehicle_type'   => 'sometimes|string|max:100',
            'q'              => 'sometimes|string|max:255',

            // ── Driver ──────────────────────────────────────────────────────
            'driver_gender'        => 'sometimes|in:M,F',
            'min_driver_rating'    => 'sometimes|numeric|between:1,5',
            'verified_driver_only' => 'sometimes|boolean',

            // ── Scope toggles ───────────────────────────────────────────────
            'include_full' => 'sometimes|boolean',
            'include_past' => 'sometimes|boolean',
            'exclude_own'  => 'sometimes|boolean',

            // ── Response shape ──────────────────────────────────────────────
            'with_counts' => 'sometimes|boolean',

            // ── Sorting / pagination ────────────────────────────────────────
            'sort_by'  => 'sometimes|in:departure_time,price,seats,created_at,distance',
            'sort_dir' => 'sometimes|in:asc,desc',
            'per_page' => 'sometimes|integer|min:1|max:50',
            'page'     => 'sometimes|integer|min:1',
        ];
    }

    public function messages(): array
    {
        return [
            'city.in'              => 'city must be one of the 14 Syrian governorates.',
            'direction.in'         => 'direction must be one of: from, to, both.',
            'max_price.gte'        => 'max_price must be greater than or equal to min_price.',
            'max_seats.gte'        => 'max_seats must be greater than or equal to seats.',
            'date_to.after_or_equal' => 'date_to must be on or after date_from.',
            'sort_by.in'           => 'sort_by must be one of: departure_time, price, seats, created_at, distance.',
            'per_page.max'         => 'per_page cannot exceed 50.',
        ];
    }
}
