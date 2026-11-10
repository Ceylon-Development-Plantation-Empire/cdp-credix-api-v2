<?php

declare(strict_types=1);

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
            // The approver decides: an amount, or a percentage of what was asked for, and the term.
            'approved_amount' => ['nullable', 'decimal:0,2', 'gt:0', 'max:9999999999999', 'prohibits:approved_percent'],
            // Up to 100 normally; above that (and the other exceptions) only as a special approval.
            'approved_percent' => ['nullable', 'decimal:0,2', 'gt:0', 'max:1000'],
            'tenure' => ['nullable', 'integer', 'min:1', 'max:600'],
            // One-off exception for this application: above the request or outside the product's limits.
            'special_approval' => ['sometimes', 'boolean'],
            'rate' => ['nullable', 'decimal:0,4', 'min:0', 'max:100'],
            'members' => ['sometimes', 'array', 'max:100'],
            'members.*.customer_public_id' => ['required', 'string', 'ulid', 'distinct'],
            'members.*.amount' => ['required', 'decimal:0,2', 'gt:0', 'max:9999999999999'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
