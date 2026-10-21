<?php

namespace App\Http\Resources\Customers;

use App\Models\CustomerBankAccount;
use App\Support\Mask;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The full account number is decrypted only for users with "Customer View Sensitive".
 *
 * @mixin CustomerBankAccount
 */
class BankAccountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'bank_name' => $this->bank_name,
            'branch_name' => $this->branch_name,
            'account_holder_name' => $this->account_holder_name,
            'account_number' => Mask::canSeeSensitive() ? $this->account_number : $this->maskedNumber(),
            'is_primary' => $this->is_primary,
            'is_active' => $this->is_active,
        ];
    }
}
