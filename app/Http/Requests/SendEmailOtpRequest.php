<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SendEmailOtpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'type' => ['sometimes', 'in:EMAIL_VERIFICATION,PASSWORD_RESET'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.required' => 'Email address is required.',
            'email.email' => 'Please provide a valid email address.',
        ];
    }
}
