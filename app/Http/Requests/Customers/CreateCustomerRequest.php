<?php

declare(strict_types=1);

namespace App\Http\Requests\Customers;

use App\Enums\LookupType;
use App\Http\Requests\Concerns\FormatsValidationErrors;
use App\Http\Requests\Customers\Concerns\CustomerFieldRules;
use App\Support\Lookups\CustomFields;
use Illuminate\Foundation\Http\FormRequest;

class CreateCustomerRequest extends FormRequest
{
    use CustomerFieldRules, FormatsValidationErrors;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // Plus the custom customer fields defined as data (lookups custom_field.customer).
        return $this->customerRules('required') + CustomFields::rules(LookupType::CustomerField);
    }
}
