<?php

namespace App\Http\Requests\Customers;

use App\Http\Requests\Concerns\FormatsValidationErrors;
use App\Http\Requests\Customers\Concerns\CustomerFieldRules;
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
        return $this->customerRules('required');
    }
}
