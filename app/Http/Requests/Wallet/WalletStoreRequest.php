<?php

namespace App\Http\Requests\Wallet;

use Illuminate\Foundation\Http\FormRequest;

class WalletStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => 'required|in:charge,withdraw',
            'amount' => 'required|numeric|min:0.01|max:10000000',
            'notes' => 'nullable|string|max:500',
            'user_notes' => 'nullable|string|max:500',
        ];
    }

    public function notes(): ?string
    {
        $validated = $this->validated();

        return $validated['notes'] ?? $validated['user_notes'] ?? null;
    }
}
