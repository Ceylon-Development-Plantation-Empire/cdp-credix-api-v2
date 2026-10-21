<?php

namespace App\Http\Requests\Loans;

use App\Http\Requests\Concerns\FormatsValidationErrors;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The rate is ignored for Islamic loans: their restructure never adds profit.
 */
class RestructureRequest extends FormRequest
{
    use FormatsValidationErrors;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tenure' => ['required', 'integer', 'min:1', 'max:600'],
            'rate' => ['nullable', 'decimal:0,4', 'min:0', 'max:100'],
            'remarks' => ['required', 'string', 'min:3', 'max:1000'],
        ];
    }
}
