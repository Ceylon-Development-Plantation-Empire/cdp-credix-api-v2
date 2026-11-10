<?php

declare(strict_types=1);

namespace App\Http\Requests\Loans;

use App\Http\Requests\Concerns\FormatsValidationErrors;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The customer on the review link asks for a detail to be corrected.
 */
class ReviewCorrectionRequest extends FormRequest
{
    use FormatsValidationErrors;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'stage' => ['required', 'string', 'max:60'],
            'field_name' => ['required', 'string', 'max:100'],
            'current_value' => ['nullable', 'string', 'max:500'],
            'proposed_value' => ['required', 'string', 'max:500'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ];
    }
}
