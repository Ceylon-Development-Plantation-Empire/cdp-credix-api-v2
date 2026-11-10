<?php

declare(strict_types=1);

namespace App\Http\Requests\Loans;

/**
 * Money collected on a written-off loan: same fields as a settlement (amount, channel, bank
 * reference, Idempotency-Key header). The service caps it at what is still unrecovered.
 */
class RecoveryPaymentRequest extends SettleLoanRequest
{
    public function messages(): array
    {
        return [
            'idempotency_key.required' => 'The Idempotency-Key header is required for recoveries.',
        ];
    }
}
