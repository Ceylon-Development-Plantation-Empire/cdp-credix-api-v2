<?php

declare(strict_types=1);

namespace App\Http\Requests\Loans;

/**
 * Gold loan renewal: the settlement fields (amount, channel, reference, Idempotency-Key header)
 * plus an optional principal pay-down and a new term. The amount must match the quote exactly
 * (checked by the service).
 */
class RenewGoldLoanRequest extends SettleLoanRequest
{
    public function rules(): array
    {
        return parent::rules() + [
            'principal_payment' => ['nullable', 'decimal:0,2', 'min:0', 'max:9999999999999'],
            'tenure' => ['nullable', 'integer', 'min:1', 'max:120'],
        ];
    }

    public function messages(): array
    {
        return [
            'idempotency_key.required' => 'The Idempotency-Key header is required for renewals.',
        ];
    }
}
