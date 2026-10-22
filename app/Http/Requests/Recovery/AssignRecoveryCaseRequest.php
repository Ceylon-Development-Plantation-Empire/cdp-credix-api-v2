<?php

declare(strict_types=1);

namespace App\Http\Requests\Recovery;

use App\Http\Requests\Concerns\FormatsValidationErrors;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssignRecoveryCaseRequest extends FormRequest
{
    use FormatsValidationErrors;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer', Rule::exists('users', 'id')],
            'stage' => ['required', Rule::in(['internal', 'external'])],
        ];
    }
}
