<?php

declare(strict_types=1);

namespace App\Http\Requests\Loans;

use App\Http\Requests\Concerns\FormatsValidationErrors;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Permanent deletion of a deleted application: the reason is written down and the application number is typed in full.
 */
class PurgeLoanRequest extends FormRequest
{
    use FormatsValidationErrors;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'remarks' => ['required', 'string', 'min:3', 'max:1000'],
            'confirm_application_no' => ['required', 'string', 'max:40'],
        ];
    }
}
