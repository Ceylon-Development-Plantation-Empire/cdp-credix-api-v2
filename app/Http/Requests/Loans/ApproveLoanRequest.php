<?php

namespace App\Http\Requests\Loans;

use App\Http\Requests\Concerns\FormatsValidationErrors;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The approver may lower the amount (never raise it) and set the rate within the product's band.
 * For a group facility, amounts are given per member.
 */
class ApproveLoanRequest extends FormRequest
{
    use FormatsValidationErrors;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'approved_amount' => ['nullable', 'decimal:0,2', 'gt:0', 'max:9999999999999'],
            'rate' => ['nullable', 'decimal:0,4', 'min:0', 'max:100'],
            'members' => ['sometimes', 'array', 'max:100'],
            'members.*.customer_public_id' => ['required', 'string', 'ulid', 'distinct'],
            'members.*.amount' => ['required', 'decimal:0,2', 'gt:0', 'max:9999999999999'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
