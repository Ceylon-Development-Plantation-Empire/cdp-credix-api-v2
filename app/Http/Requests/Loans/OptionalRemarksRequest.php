<?php

declare(strict_types=1);

namespace App\Http\Requests\Loans;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A workflow step whose remarks are optional (verify, decline an offer).
 *
 * Validation errors keep the documented {message, errors: {field: [...]}} shape.
 */
class OptionalRemarksRequest extends FormRequest
{
    /**
     * The step's permission is checked by the controller middleware.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'remarks' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
