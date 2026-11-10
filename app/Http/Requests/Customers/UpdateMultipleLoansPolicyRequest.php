<?php

declare(strict_types=1);

namespace App\Http\Requests\Customers;

use App\Http\Requests\Concerns\FormatsValidationErrors;
use Illuminate\Foundation\Http\FormRequest;

class UpdateMultipleLoansPolicyRequest extends FormRequest
{
    use FormatsValidationErrors;

    public function authorize(): bool
    {
        return $this->user()?->can('Loan Multiple Open Allow') ?? false;
    }

    public function rules(): array
    {
        $cap = (int) (\App\Models\Setting::value('loan.max_concurrent_loans_cap', 50));

        return [
            'allow_multiple_loans' => ['required', 'boolean'],
            'max_open_loans' => [
                'required_if:allow_multiple_loans,true',
                'nullable',
                'integer',
                'min:2',
                "max:{$cap}",
            ],
            'reason' => [
                'required_if:allow_multiple_loans,true',
                'nullable',
                'string',
                'min:10',
                'max:500',
            ],
        ];
    }

    public function messages(): array
    {
        $cap = (int) (\App\Models\Setting::value('loan.max_concurrent_loans_cap', 50));

        return [
            'allow_multiple_loans.required' => 'The multiple loans authorization setting is required.',
            'max_open_loans.required_if' => "Please specify the maximum allowed open loans (between 2 and {$cap}).",
            'max_open_loans.min' => 'Maximum concurrent open loans must be at least 2.',
            'max_open_loans.max' => "Maximum concurrent open loans cannot exceed {$cap}.",
            'reason.required_if' => 'A valid justification (minimum 10 characters) is required when authorizing multiple loans.',
            'reason.min' => 'The justification must be at least 10 characters long.',
        ];
    }
}

