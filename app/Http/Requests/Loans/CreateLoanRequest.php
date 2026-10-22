<?php

declare(strict_types=1);

namespace App\Http\Requests\Loans;

use App\Enums\BorrowerType;
use App\Enums\LookupType;
use App\Http\Requests\Concerns\FormatsValidationErrors;
use App\Support\Lookups\CustomFields;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A new application. Product limits (amount band, tenure band, borrower types) and eligibility
 * are checked by LoanApplicationService against the product and the people.
 *
 * individual: customer_public_id + requested_amount
 * joint:      customer_public_id + co_borrower_public_ids + requested_amount
 * group:      borrower_group_public_id + members [{customer_public_id, amount}]
 */
class CreateLoanRequest extends FormRequest
{
    use FormatsValidationErrors;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'product_slug' => ['bail', 'required', 'string', 'max:80', Rule::exists('products', 'slug')->whereNull('deleted_at')],
            'borrower_type' => ['required', Rule::enum(BorrowerType::class)],
            'tenure' => ['required', 'integer', 'min:1', 'max:600'],
            'purpose' => ['nullable', 'string', 'max:1000'],

            'customer_public_id' => ['exclude_if:borrower_type,group', 'required', 'string', 'ulid'],
            'requested_amount' => ['exclude_if:borrower_type,group', 'required', 'decimal:0,2', 'gt:0', 'max:9999999999999'],
            'co_borrower_public_ids' => ['exclude_unless:borrower_type,joint', 'required', 'array', 'min:1', 'max:5'],
            'co_borrower_public_ids.*' => ['string', 'ulid', 'distinct', 'different:customer_public_id'],

            'borrower_group_public_id' => ['exclude_unless:borrower_type,group', 'required', 'string', 'ulid'],
            'members' => ['exclude_unless:borrower_type,group', 'required', 'array', 'min:1', 'max:100'],
            'members.*.customer_public_id' => ['required', 'string', 'ulid', 'distinct'],
            'members.*.amount' => ['required', 'decimal:0,2', 'gt:0', 'max:9999999999999'],
            // Custom loan fields defined as data (lookups custom_field.loan).
        ] + CustomFields::rules(LookupType::LoanField);
    }
}
