<?php

declare(strict_types=1);

namespace App\Http\Requests\Customers;

use App\Enums\FinancialKind;
use App\Enums\LookupType;
use App\Http\Requests\Concerns\FormatsValidationErrors;
use App\Support\Lookups\LookupRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Income, expense, asset or liability line. The whole line is sent on create and update.
 */
class CustomerFinancialRequest extends FormRequest
{
    use FormatsValidationErrors;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $kind = FinancialKind::tryFrom((string) $this->input('kind'));

        return [
            'kind' => ['required', Rule::enum(FinancialKind::class)],
            // Categories depend on the kind (salary is an income, not an asset); the lists are lookup data.
            'category' => ['required', 'string', Rule::in($kind ? app(LookupRegistry::class)->selectableCodes(LookupType::forFinancialKind($kind)) : [])],
            'description' => ['nullable', 'string', 'max:255'],
            'institution_name' => ['nullable', 'required_if:kind,liability', 'string', 'max:150'],
            'reference_no' => ['nullable', 'string', 'max:60'],
            'amount' => ['required', 'decimal:0,2', 'min:0', 'max:9999999999999'],
            'monthly_obligation' => ['nullable', 'required_if:kind,liability', 'decimal:0,2', 'min:0', 'max:9999999999999'],
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
        ];
    }

    public function messages(): array
    {
        return [
            'category.in' => 'The category is not valid for this kind.',
            'monthly_obligation.required_if' => 'The monthly installment is required for a liability (it is used to assess affordability).',
        ];
    }
}
