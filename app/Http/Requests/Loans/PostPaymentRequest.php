<?php

namespace App\Http\Requests\Loans;

use App\Enums\PaymentChannel;
use App\Http\Requests\Concerns\FormatsValidationErrors;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A repayment. Money is taken as a string with at most 2 decimals; the Idempotency-Key header
 * is required so a retried request never posts twice.
 */
class PostPaymentRequest extends FormRequest
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
            'loan_public_id' => ['required', 'string', 'ulid'],
            'amount' => ['required', 'decimal:0,2', 'gt:0', 'max:9999999999999'],
            'value_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'channel' => ['required', Rule::enum(PaymentChannel::class)->only([PaymentChannel::Cash, PaymentChannel::BankTransfer, PaymentChannel::Cheque, PaymentChannel::Online])],
            'external_reference' => ['required_unless:channel,cash', 'nullable', 'string', 'max:60'],
            'payer_customer_public_id' => ['nullable', 'string', 'ulid'],
            'narration' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'idempotency_key.required' => 'The Idempotency-Key header is required for payments.',
        ];
    }
}
