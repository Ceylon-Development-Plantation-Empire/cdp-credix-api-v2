<?php

declare(strict_types=1);

namespace App\Http\Requests\Staff;

use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /employees/list: names, codes and branches only (pickers).
 *
 * Validation errors keep the documented {message, errors: {field: [...]}} shape.
 */
class EmployeeDirectoryRequest extends FormRequest
{
    /**
     * Any back-office user (route group user.type:admin,staff).
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
            'search' => ['nullable', 'string', 'max:100'],
            'branch_id' => ['nullable', 'integer'],
        ];
    }
}
