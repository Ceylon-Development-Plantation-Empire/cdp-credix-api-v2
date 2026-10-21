<?php

namespace App\Http\Requests\Approvals;

use App\Http\Requests\Concerns\FormatsValidationErrors;
use Illuminate\Foundation\Http\FormRequest;

class RejectRequest extends FormRequest
{
    use FormatsValidationErrors;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'remarks' => ['required', 'string', 'min:5', 'max:1000'],
        ];
    }
}
