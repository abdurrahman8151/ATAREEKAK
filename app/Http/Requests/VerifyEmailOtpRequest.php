<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VerifyEmailOtpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'exists:users,email'],
            'otp_code' => ['required', 'string', 'size:6', 'regex:/^[0-9]{6}$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.exists' => 'No account found with this email.',
            'otp_code.size' => 'Code must be exactly 6 digits.',
            'otp_code.regex' => 'Code must contain numbers only.',
        ];
    }
}
