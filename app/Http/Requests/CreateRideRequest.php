<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateRideRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Authorization handled in controller
    }

    public function rules(): array
    {
        return [
            'pickup_address' => 'required_without_all:pickup_lat,pickup_lng|string|max:255',
            'pickup_lat' => 'required_with:pickup_lng|numeric|between:-90,90',
            'pickup_lng' => 'required_with:pickup_lat|numeric|between:-180,180',

            'destination_address' => 'required_without_all:destination_lat,destination_lng|string|max:255',
            'destination_lat' => 'required_with:destination_lng|numeric|between:-90,90',
            'destination_lng' => 'required_with:destination_lat|numeric|between:-180,180',

            'departure_time' => [
                'required',
                'date',
                'after:'.now()->addMinutes(5)->toDateTimeString(),
            ],

            'available_seats' => 'required|integer|min:1|max:8',
            'price_per_seat' => 'required|numeric|min:100|max:100000',
            'payment_method' => 'required|in:cash,e-pay',
            'booking_type' => 'required|in:direct,request',
            'communication_number' => 'required|regex:/^09\d{8}$/',
            'vehicle_type' => 'required|string|max:100',
            'notes' => 'nullable|string|max:500',

            // Optional route data
            'route_index' => 'nullable|integer|min:0',
            'route_geometry' => 'nullable|array',
            'distance' => 'nullable|numeric|min:0',
            'duration' => 'nullable|numeric|min:0',
        ];
    }

    public function messages(): array
    {
        return [
            'pickup_address.required_without_all' => 'Please provide either pickup address or coordinates',
            'destination_address.required_without_all' => 'Please provide either destination address or coordinates',
            'departure_time.after' => 'Departure time must be at least 5 minutes in the future',
            'communication_number.regex' => 'Communication number must be a valid Syrian mobile number (09XXXXXXXX)',
            'price_per_seat.min' => 'Price per seat must be at least 100 SYP',
            'available_seats.max' => 'Maximum 8 seats allowed',
        ];
    }
}
