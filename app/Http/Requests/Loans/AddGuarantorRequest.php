<?php

namespace App\Http\Requests\Loans;

use App\Http\Requests\Concerns\FormatsValidationErrors;
use Illuminate\Foundation\Http\FormRequest;

class AddGuarantorRequest extends FormRequest
{
    use FormatsValidationErrors;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customer_public_id' => ['required', 'string', 'ulid'],
            'relationship' => ['nullable', 'string', 'in:family,spouse,relative,friend,employer,colleague,business_partner,other'],
        ];
    }
}
