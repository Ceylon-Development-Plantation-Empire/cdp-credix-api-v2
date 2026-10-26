<?php

declare(strict_types=1);

namespace App\Http\Requests\Loans;

use App\Enums\LookupType;
use App\Http\Requests\Concerns\FormatsValidationErrors;
use App\Support\Lookups\CustomFields;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Corrections to a draft or returned application. The product, borrower type and main borrower
 * cannot change: that is a different application.
 */
class UpdateLoanRequest extends FormRequest
{
    use Concerns\ApplicationFields, FormatsValidationErrors;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'product_slug' => ['prohibited'],
            'borrower_type' => ['prohibited'],
            'customer_public_id' => ['prohibited'],
            'borrower_group_public_id' => ['prohibited'],

            // The reason for a change to an application that was already submitted (required then, checked by the service).
            'remarks' => ['sometimes', 'nullable', 'string', 'min:3', 'max:1000'],
            'tenure' => ['sometimes', 'integer', 'min:1', 'max:600'],
            'purpose' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'requested_amount' => ['sometimes', 'decimal:0,2', 'gt:0', 'max:9999999999999'],
            'co_borrower_public_ids' => ['sometimes', 'array', 'min:1', 'max:5'],
            'co_borrower_public_ids.*' => ['string', 'ulid', 'distinct'],
            'members' => ['sometimes', 'array', 'min:1', 'max:100'],
            'members.*.customer_public_id' => ['required', 'string', 'ulid', 'distinct'],
            'members.*.amount' => ['required', 'decimal:0,2', 'gt:0', 'max:9999999999999'],
            // Custom loan fields defined as data (lookups custom_field.loan); meta is replaced as a whole.
        ] + $this->applicationFieldRules() + CustomFields::rules(LookupType::LoanField);
    }
}
