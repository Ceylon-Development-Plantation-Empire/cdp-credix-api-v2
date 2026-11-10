<?php

declare(strict_types=1);

namespace App\Http\Requests\Loans;

use App\Enums\PaymentChannel;
use App\Http\Requests\Concerns\FormatsValidationErrors;
use App\Http\Requests\Concerns\RequiresIdempotencyKey;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /loans/{publicId}/settle: early settlement. The amount must be today's quote exactly
 * (checked by the service); Idempotency-Key header required.
 */
class SettleLoanRequest extends FormRequest
{
    use FormatsValidationErrors, RequiresIdempotencyKey;

    /**
     * Permission "Loan Settle" is checked by the controller middleware.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return $this->idempotencyKeyRules() + [
            'amount' => ['required', 'decimal:0,2', 'gt:0', 'max:9999999999999'],
            'channel' => ['required', Rule::enum(PaymentChannel::class)->only([PaymentChannel::Cash, PaymentChannel::BankTransfer, PaymentChannel::Cheque, PaymentChannel::Online])],
            'external_reference' => ['required_unless:channel,cash', 'nullable', 'string', 'max:60'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->idempotencyKeyMessage('settlements');
    }
}
