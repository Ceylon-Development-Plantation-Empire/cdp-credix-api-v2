<?php

namespace App\Http\Requests\Loans;

use App\Http\Requests\Concerns\FormatsValidationErrors;
use Illuminate\Foundation\Http\FormRequest;

/**
 * registration_ref: mortgage bond number or CR endorsement; custody_ref: gold packet number and
 * vault location. CollateralService decides which one the type needs.
 */
class PerfectCollateralRequest extends FormRequest
{
    use FormatsValidationErrors;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'registration_ref' => ['nullable', 'string', 'max:60'],
            'custody_ref' => ['nullable', 'string', 'max:60'],
        ];
    }
}
