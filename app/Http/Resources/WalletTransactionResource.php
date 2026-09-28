<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WalletTransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'amount' => number_format((float) $this->amount, 2, '.', ''),
            'balance_after' => number_format((float) $this->new_balance, 2, '.', ''),
            'description' => $this->description,
            'reference' => $this->reference,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
