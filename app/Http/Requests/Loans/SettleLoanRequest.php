<?php

namespace App\Http\Requests\Loans;

use App\Enums\PaymentChannel;
use App\Http\Requests\Concerns\FormatsValidationErrors;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Early settlement. The amount must be today's quote exactly (checked by the service).
 */
class SettleLoanRequest extends FormRequest
{
    use FormatsValidationErrors;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
    }

    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'string', 'min:8', 'max:64', 'regex:/^[A-Za-z0-9_\-]+$/'],
            'amount' => ['required', 'decimal:0,2', 'gt:0', 'max:9999999999999'],
            'channel' => ['required', Rule::enum(PaymentChannel::class)->only([PaymentChannel::Cash, PaymentChannel::BankTransfer, PaymentChannel::Cheque, PaymentChannel::Online])],
            'external_reference' => ['required_unless:channel,cash', 'nullable', 'string', 'max:60'],
        ];
    }

    public function messages(): array
    {
        return [
            'idempotency_key.required' => 'The Idempotency-Key header is required for settlements.',
        ];
    }
}
