<?php

declare(strict_types=1);

namespace App\Http\Requests\Loans;

use App\Http\Requests\Concerns\FormatsValidationErrors;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The only parts of a posted payment that may be corrected: its free text. Money is corrected by a reversal.
 */
class UpdatePaymentRequest extends FormRequest
{
    use FormatsValidationErrors;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'narration' => ['sometimes', 'nullable', 'string', 'max:255'],
            'external_reference' => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }
}
