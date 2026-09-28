<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

/**
 * Book Ride Request
 *
 * FIXED: Added idempotency_key to prevent duplicate bookings
 */
class BookRideRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'seats' => 'required|integer|min:1|max:8',
            'communication_number' => 'required|regex:/^09\d{8}$/',
            'idempotency_key' => 'required|uuid', // ✅ ADDED for preventing duplicates
        ];
    }

    public function messages(): array
    {
        return [
            'seats.required' => 'Please specify the number of seats',
            'seats.min' => 'Must request at least 1 seat',
            'seats.max' => 'Cannot request more than 8 seats',
            'communication_number.required' => 'Communication number is required',
            'communication_number.regex' => 'Communication number must be a valid Syrian mobile number (09XXXXXXXX)',
            'idempotency_key.required' => 'Request ID is required',
            'idempotency_key.uuid' => 'Invalid request ID format',
        ];
    }

    /**
     * Prepare data for validation
     *
     * Generate idempotency key if not provided (for backward compatibility)
     */
    protected function prepareForValidation(): void
    {
        if (! $this->has('idempotency_key')) {
            $this->merge([
                'idempotency_key' => (string) Str::uuid(),
            ]);
        }
    }
}
