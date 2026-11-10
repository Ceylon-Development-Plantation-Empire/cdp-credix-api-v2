<?php

declare(strict_types=1);

namespace App\Http\Requests\Approvals;

use App\Http\Requests\Concerns\FormatsValidationErrors;
use Illuminate\Foundation\Http\FormRequest;

class ApproveRequest extends FormRequest
{
    use FormatsValidationErrors;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * reference is required for Shariah actions; ApprovalRequestService checks that per action.
     */
    public function rules(): array
    {
        return [
            'reference' => ['nullable', 'string', 'max:100'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
