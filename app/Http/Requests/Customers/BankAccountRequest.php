<?php

declare(strict_types=1);

namespace App\Http\Requests\Customers;

use App\Enums\LookupType;
use App\Http\Requests\Concerns\FormatsValidationErrors;
use App\Support\Lookups\LookupRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create (POST) needs every required field; update (PUT) accepts any subset.
 */
class BankAccountRequest extends FormRequest
{
    use FormatsValidationErrors;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $presence = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            // One spelling per bank (transfers and reports group by it): a name from the bank list (lookups).
            'bank_name' => [$presence, 'string', 'max:150', Rule::in(app(LookupRegistry::class)->selectableLabels(LookupType::Bank))],
            'branch_name' => ['nullable', 'string', 'max:100'],
            'account_holder_name' => [$presence, 'string', 'max:150'],
            // Sri Lankan account numbers are digits only, 6 to 20 long depending on the bank.
            'account_number' => [$presence, 'string', 'regex:/^\d{6,20}$/'],
            'is_primary' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'account_number.regex' => 'The account number must be 6 to 20 digits.',
        ];
    }
}
