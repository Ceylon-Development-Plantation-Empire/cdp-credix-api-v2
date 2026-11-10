<?php

declare(strict_types=1);

namespace App\Http\Requests\Customers;

use App\Enums\IdType;
use App\Enums\LookupType;
use App\Http\Requests\Concerns\FormatsValidationErrors;
use App\Http\Requests\Customers\Concerns\CustomerFieldRules;
use App\Models\Customer;
use App\Rules\ValidIdNumber;
use App\Support\Lookups\CustomFields;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCustomerRequest extends FormRequest
{
    use CustomerFieldRules, FormatsValidationErrors;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = $this->customerRules('sometimes');

        // When only the number changes, validate it against the customer's current ID type.
        $customer = Customer::where('public_id', $this->route('publicId'))->first();
        $rules['id_number'] = ['sometimes', 'string', 'max:30', new ValidIdNumber($customer?->id_type ?? IdType::Nic)];
        $rules['id_type'][] = 'required_with:id_number';

        // Custom customer fields defined as data; meta is replaced as a whole when sent.
        return $rules + CustomFields::rules(LookupType::CustomerField);
    }
}
