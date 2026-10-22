<?php

declare(strict_types=1);

namespace App\Http\Requests\Loans;

use App\Enums\PaymentChannel;
use App\Http\Requests\Concerns\FormatsValidationErrors;
use App\Http\Requests\Concerns\RequiresIdempotencyKey;
use App\Support\BusinessDate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /payments: a repayment. Money is a string with at most 2 decimals and must be positive; the
 * Idempotency-Key header is required so a retried request never posts twice. What the money pays
 * is worked out by the server (allocation waterfall), never sent by the client.
 */
class PostPaymentRequest extends FormRequest
{
    use FormatsValidationErrors, RequiresIdempotencyKey;

    /**
     * Permission "Payment Create" is checked by the controller middleware.
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
            'loan_public_id' => ['required', 'string', 'ulid'],
            'amount' => ['required', 'decimal:0,2', 'gt:0', 'max:9999999999999'],
            'value_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.BusinessDate::today()->toDateString()],
            'channel' => ['required', Rule::enum(PaymentChannel::class)->only([PaymentChannel::Cash, PaymentChannel::BankTransfer, PaymentChannel::Cheque, PaymentChannel::Online])],
            'external_reference' => ['required_unless:channel,cash', 'nullable', 'string', 'max:60'],
            'payer_customer_public_id' => ['nullable', 'string', 'ulid'],
            'narration' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->idempotencyKeyMessage('payments');
    }
}
