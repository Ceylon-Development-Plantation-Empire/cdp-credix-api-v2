<?php

declare(strict_types=1);

namespace App\Http\Requests\Loans;

use App\Http\Requests\Concerns\FormatsValidationErrors;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * An officer answers a customer's correction request.
 */
class ResolveCorrectionRequest extends FormRequest
{
    use FormatsValidationErrors;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'outcome' => ['required', Rule::in(['accepted', 'declined'])],
            'remarks' => ['required', 'string', 'min:3', 'max:1000'],
        ];
    }
}
