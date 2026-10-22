<?php

declare(strict_types=1);

namespace App\Http\Requests\Loans;

use App\Enums\PaymentChannel;
use App\Http\Requests\Concerns\FormatsValidationErrors;
use App\Http\Requests\Concerns\RequiresIdempotencyKey;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /loans/{publicId}/disburse. Needs the Idempotency-Key header so a retried request is
 * recognised instead of paying out twice.
 */
class DisburseLoanRequest extends FormRequest
{
    use FormatsValidationErrors, RequiresIdempotencyKey;

    /**
     * Permission "Loan Disburse" is checked by the controller middleware.
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
            'channel' => ['required', Rule::enum(PaymentChannel::class)->only([PaymentChannel::Cash, PaymentChannel::BankTransfer, PaymentChannel::Cheque])],
            'external_reference' => ['required_unless:channel,cash', 'nullable', 'string', 'max:60'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->idempotencyKeyMessage('disbursements');
    }
}
