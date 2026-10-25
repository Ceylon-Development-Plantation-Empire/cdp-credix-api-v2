<?php

declare(strict_types=1);

namespace App\Http\Requests\Recovery;

use App\Http\Requests\Concerns\FormatsValidationErrors;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Open a recovery case by hand for a loan in arrears.
 */
class OpenRecoveryCaseRequest extends FormRequest
{
    use FormatsValidationErrors;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'loan_public_id' => ['required', 'string', 'ulid'],
            'remarks' => ['required', 'string', 'min:3', 'max:1000'],
        ];
    }
}
